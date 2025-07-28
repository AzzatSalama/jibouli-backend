<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Order;
use Illuminate\Http\Request;
use App\Models\DeliveryPerson;
use App\Services\AuthEntityService;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class DeliveryPersonController extends Controller
{
    public function index()
    {
        $deliveryPersons = Cache::remember('delivery_person', 60, function () {
            return DeliveryPerson::with('user') // Join with the User table
                ->withCount([
                    'orders as total_orders',
                    'orders as delivered_orders' => fn($q) => $q->where('status', 'delivered'),
                    'orders as canceled_orders' => fn($q) => $q->where('status', 'canceled'),
                    'orders as pending_orders' => fn($q) => $q->where('status', 'pending')
                ])->get()->map(function ($deliveryPerson) {
                    return [
                        'id' => $deliveryPerson->id,
                        'livreur_name' => $deliveryPerson->delivery_person_name,
                        'livreur_phone' => $deliveryPerson->delivery_phone,
                        'email' => $deliveryPerson->user->email, // Include email from the User table
                        'status' => $deliveryPerson->is_available ? 'available' : 'not available',
                        'solde' => $deliveryPerson->balance,
                        'order_stats' => [
                            'total' => $deliveryPerson->total_orders,
                            'delivered' => $deliveryPerson->delivered_orders,
                            'canceled' => $deliveryPerson->canceled_orders,
                            'pending' => $deliveryPerson->pending_orders
                        ]
                    ];
                });
        });

        return response()->json($deliveryPersons);
    }

    /**
     * Store a newly created delivery person
     */
    public function store(Request $request)
    {
        try {

            $validator = Validator::make($request->all(), [
                'livreur_name' => 'required|string|max:255',
                'livreur_phone' => 'nullable|string|unique:delivery_person,delivery_phone|max:20',
                'solde' => 'sometimes|numeric',
                'email' => 'required|string|email|max:255|unique:user,email',
                'password' => 'required|string|min:8',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 422,
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();

            $user = User::create([
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role' => "delivery_person",
            ]);

            $deliveryPerson = DeliveryPerson::create([
                'delivery_person_name' => $validated['livreur_name'],
                'delivery_phone' => $validated['livreur_phone'],
                'is_available' => false,
                'balance' => $validated['solde'] ?? 0,
                'user_id' => $user->id,
            ]);
            Cache::forget('delivery_person');
            return response()->json(['message' => "Le livreur et crée avec succes"], 201);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    /**
     * Display delivery person details with orders and balance
     */
    public function show(AuthEntityService $authEntityService)
    {
        try {

            $user = Auth::guard('sanctum')->user();
            $deliveryPerson = DeliveryPerson::where('user_id', $user->id)->firstOrFail();

            $activeOrders = $deliveryPerson->orders()
                ->where('status', 'accepted')
                ->with('client') // Ensure the 'client' relationship is loaded
                ->get();

            foreach ($activeOrders as $order) {
                if ($order->user->role == 'partner')
                    $order->partner = $authEntityService->getUserById($order->user_id);
                else if ($order->partner_id)
                    $order->partner = $authEntityService->getPartnerById($order->partner_id);
            }

            return response()->json([
                'delivery_person' => $deliveryPerson,
                'active_orders' => $activeOrders,
                'balance' => $deliveryPerson->balance
            ]);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    /**
     * Update delivery person information
     */
    public function update(Request $request, AuthEntityService $authEntityService, $id = null)
    {
        $authUser = Auth::guard('sanctum')->user();
        // dd($authUser->role);
        $deliveryPersonId = $authUser->role == "admin" ? $id : $authUser->id; // if the person trying to update is an admin, he can update any delivery person, otherwise it will be a delivery driver who can only update his own data

        try {
            $deliveryPerson = DeliveryPerson::where('user_id', $deliveryPersonId)->firstOrFail();

            $validator = Validator::make($request->all(), [
                'livreur_name' => 'sometimes|string|max:255',
                'livreur_phone' => 'sometimes|nullable|string|max:20|unique:delivery_person,delivery_phone,' . $authEntityService->getUserById($deliveryPerson->user_id)->email,
                'email' => 'sometimes|string|email|max:255|unique:user,email,' . $authEntityService->getUserById($deliveryPerson->user_id)->email,
                'password' => 'sometimes|string|min:8|confirmed',
                'is_available' => 'sometimes|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 422,
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();

            // Update related user data if provided
            if (isset($validated['email']) || isset($validated['password'])) {
                $deliveryPerson->user->update([
                    'email' => $validated['email'] ?? $deliveryPerson->user->email,
                    'password' => isset($validated['password']) ? Hash::make($validated['password']) : $deliveryPerson->user->password,
                ]);
            }

            // Update delivery person data
            $deliveryPerson->update([
                'delivery_person_name' => $validated['livreur_name'] ?? $deliveryPerson->delivery_person_name,
                'delivery_phone' => $validated['livreur_phone'] ?? $deliveryPerson->delivery_phone,
                'is_available' => $deliveryPerson->balance > 2
                    ? ($validated['is_available'] ?? $deliveryPerson->is_available)
                    : false,
            ]);

            Cache::forget('delivery_person');

            return response()->json(['message' => 'Delivery person updated successfully'], 200);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage(), 'line' => $th->getLine()], 500);
        }
    }

    /**
     * Update delivery person's location
     */
    public function updateLocation(Request $request)
    {
        // Validate request data
        $validator = Validator::make($request->all(), [
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = Auth::guard('sanctum')->user();

            // Update current location
            $deliveryPerson = DeliveryPerson::where('user_id', $user->id)->firstOrFail();
            $deliveryPerson->update([
                'latitude' => $request->latitude,
                'longitude' => $request->longitude
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Location updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update location',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete delivery person (if no associated orders)
     */
    public function destroy(string $id)
    {
        try {
            $deliveryPerson = DeliveryPerson::findOrFail($id);

            if ($deliveryPerson->orders()->exists()) {
                return response()->json([
                    'message' => 'Cannot delete delivery person with associated orders'
                ], 409);
            }

            $deliveryPerson->delete();
            Cache::forget('delivery_person');
            return response()->json(null, 204);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Error fetching employee data',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function activeDeliveryPersons()
    {
        try {
            $activeDeliveryPersons = DeliveryPerson::where('is_available', true)
                ->whereDoesntHave('orders', function ($query) {
                    $query->where('status', 'accepted');
                })
                ->get();

            $count = $activeDeliveryPersons->count();

            return response()->json([
                'count' => $count,
                'delivery_person' => $activeDeliveryPersons
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Error fetching employee data',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function getDeliveryDriverLocation($orderId)
    {
        try {
            $order = Order::findOrFail($orderId);

            if ($order->status !== 'accepted') {
                return response()->json([
                    'status' => $order->status,
                    'message' => 'Order is not accepted'
                ], 422);
            }

            $deliveryPerson = $order->deliveryPerson;

            if (!$deliveryPerson) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No delivery driver associated with this order'
                ], 204);
            }

            return response()->json([
                'status' => 'success',
                'latitude' => $deliveryPerson->latitude,
                'longitude' => $deliveryPerson->longitude,
                'delivery_phone' => $deliveryPerson->delivery_phone
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve delivery driver location',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}