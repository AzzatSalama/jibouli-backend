<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Order;
use App\Models\Client;
use App\Models\Partner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\CanceledOrdersCause;
use App\Services\AuthEntityService;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class PartnerController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function index()
    {
        $partners = Cache::remember('partners', 60, function () {
            return Partner::with('user:id,email')->withCount([
                'orders as total_orders' => fn($q) => $q->where('user_id', '=', $q->getModel()->id),
                'orders as delivered_orders' => fn($q) => $q->where('user_id', '=', $q->getModel()->id)->where('status', 'delivered'),
                'orders as canceled_orders' => fn($q) => $q->where('user_id', '=', $q->getModel()->id)->where('status', 'canceled'),
                'orders as pending_orders' => fn($q) => $q->where('user_id', '=', $q->getModel()->id)->where('status', 'pending')
            ])->get()->map(function ($partner) {
                return [
                    'id' => $partner->id,
                    'name' => $partner->name,
                    'phone' => $partner->phone,
                    'address' => $partner->address,
                    'email' => $partner->user->email,
                    'lat' => $partner->lat,
                    'lang' => $partner->lang,
                    'type' => $partner->type,
                    'order_stats' => [
                        'total' => $partner->total_orders,
                        'delivered' => $partner->delivered_orders,
                        'canceled' => $partner->canceled_orders,
                        'pending' => $partner->pending_orders
                    ]
                ];
            });
        });

        if ($partners->isEmpty()) {
            return response()->json(['message' => 'no partner registred yet'], 204);
        }

        return response()->json($partners, 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20|unique:partner,phone',
            'address' => 'required|string|max:500',
            'lat' => 'nullable|numeric',
            'lang' => 'nullable|numeric',
            'type' => 'required|string|max:50',
            'email' => 'required|email|unique:user,email',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $validated = $validator->validated();

        // Create the user first
        $user = User::create([
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => 'partner',
        ]);

        // Then create the partner using the user_id
        $partner = Partner::create(array_merge(
            $request->only(['name', 'phone', 'address', 'lat', 'lang', 'type']),
            ['user_id' => $user->id]
        ));

        return response()->json($partner, 201);
    }

    public function update($id, Request $request)
    {
        $partner = Partner::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20|unique:partner,phone,' . $partner->id,
            'address' => 'sometimes|string|max:500',
            'lat' => 'nullable|numeric',
            'lang' => 'nullable|numeric',
            'type' => 'sometimes|string|max:50',
            'email' => 'sometimes|email|unique:user,email,' . $partner->user_id,
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $validated = $validator->validated();

        // Update the associated user if email or password is provided
        if (isset($validated['email'])) {
            $partner->user->update([
                'email' => $validated['email'] ?? $partner->user->email,
            ]);
        }

        // Update the partner details
        $partner->update($request->only(['name', 'phone', 'address', 'lat', 'lang', 'type']));

        return response()->json($partner, 200);
    }



    public function delete($id)
    {
        $order = Order::findOrFail($id);

        if ($order->status === 'delivered' || $order->status === 'canceled') {
            return response()->json(['message' => 'Cannot delete delivered or canceled orders.'], 422);
        }

        $order->delete();
        return response()->json(['message' => 'Order deleted successfully.'], 200);
    }

    public function addOrder(Request $request, AuthEntityService $authEntityService)
    {
        $user = Auth::guard('sanctum')->user();
        $validator = Validator::make($request->all(), [
            'client_phone' => 'required|string|max:20',
            'client_name' => 'required|string|max:255',
            'client_address' => 'required|string|max:500',
            'request' => 'required|string',
            'client_notes' => 'nullable|string',
            'user_notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $validated = $validator->validated();

        return DB::transaction(function () use ($validated, $user, $authEntityService) {
            $clientData = [
                'client_phone' => $validated['client_phone'],
                'client_name' => $validated['client_name'],
                'client_address' => $validated['client_address']
            ];

            $client = Client::firstOrCreate(
                ['client_phone' => $validated['client_phone']],
                array_merge($clientData, ['added_by' => $user->id])
            );

            $order = Order::create([
                'client_id' => $client->id,
                'request' => $validated['request'],
                'user_notes' => $validated['user_notes'],
                'client_notes' => $validated['client_notes'],
                'status' => 'pending',
                'user_id' => $user->id,
            ]);

            cache()->forget('pending_orders'); // Clear the cache for orders

            //send notification to drivers
            $this->notificationService->sendNotification(
                'Nouvelle commande',
                "Une nouvelle commande à été crée",
                "/livreur.html",
                $this->notificationService->getDeliveryDriversTokens()
            );
            //send notification to admins
            $orderCreator = $authEntityService->getUserById($order->user_id);
            $this->notificationService->sendNotification(
                'Nouvelle commande',
                "Une nouvelle commande à été crée de la part de:" . $orderCreator->name,
                "/gererOrders.html",
                $this->notificationService->getAdminsTokens()
            );
            return response()->json($order, 201);
        });
    }

    public function orders()
    {
        $user = Auth::guard('sanctum')->user();

        $orders = Order::with(['client', 'user', 'deliveryPerson'])
            ->latest()
            ->get()
            ->where('user_id', $user->id)
            ->map(function ($order) {
                if ($order->status === 'canceled') {
                    $order->cancellation_cause = CanceledOrdersCause::where('order_id', $order->id)->value('cause');
                }
                return $order;
            });

        return response()->json($orders, 200);
    }

    // public function updateOrder($id, Request $request)
    // {
    //     $order = Order::findOrFail($id);

    //     if ($order->status === 'delivered' || $order->status === 'canceled') {
    //         return response()->json(['message' => 'Cannot update delivered or canceled orders.'], 422);
    //     }

    //     $validator = Validator::make($request->all(), [
    //         'client_notes' => 'nullable|string',
    //         'user_notes' => 'nullable|string',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json($validator->errors(), 422);
    //     }

    //     if ($request->has('client_notes')) {
    //         $order->client_notes = $request->client_notes;
    //     }
    //     if ($request->has('user_notes')) {
    //         $order->user_notes = $request->user_notes;
    //     }
    // }

    public function updateOrder(Request $request, $id)
    {
        $order = Order::with('client')->findOrFail($id);
        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }
        // Verify partner owns the order
        $user = Auth::guard('sanctum')->user();
        if ($order->user_id !== $user->id) {
            abort(403, 'Unauthorized action.');
        }

        $validator = Validator::make($request->all(), [
            'client_name' => 'required|string|max:255',
            'client_phone' => 'required|string|max:20',
            'client_address' => 'required|string|max:500',
            'request' => 'required|string',
            'client_notes' => 'nullable|string',
            'user_notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }
        $validated = $validator->validated();

        return DB::transaction(function () use ($order, $validated) {
            // Update client
            $order->client->update([
                'client_name' => $validated['client_name'],
                'client_phone' => $validated['client_phone'],
                'client_address' => $validated['client_address']
            ]);

            // Update order
            $order->update([
                'request' => $validated['request'],
                'client_notes' => $validated['client_notes'],
                'user_notes' => $validated['user_notes']
            ]);

            return $order->load('client');
        });
    }

    public function show($id)
    {
        $order = Order::with(['client', 'deliveryPerson'])->find($id);

        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        return response()->json([
            'order' => $order,
        ], 200);
    }

    public function deleteOrder(string $id)
    {
        try {
            $order = Order::findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'La commande n\'est pas trouvé'], 404);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while retrieving the order'], 500);
        }

        try {

            $user = Auth::guard('sanctum')->user();
            // Verify partner owns the order
            if ($order->user_id !== $user->id) {
                return response()->json(['message' => 'Unauthorized action'], 403);
            }

            // Prevent deletion of completed orders
            if (in_array($order->status, ['delivered', 'canceled'])) {
                return response()->json([
                    'message' => 'Cannot delete completed orders'
                ], 422);
            }
        } catch (\Throwable $th) {
            return response()->json(['message' => 'An error occurred while checking order ownership'], 500);
        }

        try {
            // Delete the order
            $order->delete();

            return response()->json(['message' => 'Order deleted successfully'], 200);
        } catch (\Exception $e) {
            // Log the exception or perform additional error handling if needed
            return response()->json([
                'message' => 'An error occurred while deleting the order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function clients()
    {
        $user = Auth::guard('sanctum')->user();
        $clients = Client::where('added_by', $user->id)->get();

        if ($clients->isEmpty()) {
            return response()->json(['message' => 'No clients found'], 204);
        }

        return response()->json($clients, 200);
    }
}
