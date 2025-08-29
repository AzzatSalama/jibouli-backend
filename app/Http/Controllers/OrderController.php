<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\User;
use App\Models\Order;
use App\Models\Client;
use App\Models\Partner;
use App\Models\Employee;
use Illuminate\Http\Request;
use App\Models\ActionOnOrder;
use App\Models\DeliveryPerson;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use App\Models\CanceledOrdersCause;
use App\Services\AuthEntityService;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Cache;
use App\Models\DeliveryDriverActivity;
use Illuminate\Support\Facades\Validator;

class OrderController extends Controller
{
    protected $notificationService, $authEntityService;

    public function __construct(NotificationService $notificationService, AuthEntityService $authEntityService)
    {
        $this->notificationService = $notificationService;
        $this->authEntityService = $authEntityService;
    }

    public function index()
    {
        try {

            $orders = Order::with(['client', 'user', 'deliveryPerson'])
                ->latest()
                ->get()
                ->map(function ($order) {
                    if ($order->status === 'canceled') {
                        $order->cancellation_cause = CanceledOrdersCause::where('order_id', $order->id)->value('cause');
                    }
                    return $order;
                });

            return response()->json($orders);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    public function pendingOrders(Request $request)
    {
        try {
            $dbName = DB::connection()->getDatabaseName();
            $cacheKey = 'pending_orders_' . $dbName;
            $orders = Cache::remember($cacheKey, 60, function () {
                return Order::where('status', 'pending')
                    ->with('client', 'partner')
                    ->orderBy('created_at', 'asc')
                    ->get();
            });

            return response()->json($orders);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request, AuthEntityService $authEntityService)
    {
        //get the authenticated user
        $user = Auth::guard('sanctum')->user();
        $validator = Validator::make($request->all(), [
            'client_phone' => 'required|string|max:20',
            'client_name' => 'required|string|max:255',
            'client_address' => 'required|string|max:500',
            'request' => 'required|string',
            'partner_id' => 'nullable|exists:partner,id',
            'client_notes' => 'nullable|string',
            'user_notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $validated = $validator->validated();

        return DB::transaction(function () use ($validated, $user, $authEntityService) {
            // Convert valid$validated data to array with all string values
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
                'user_id' => $user->id,
                'request' => $validated['request'],
                'partner_id' => $validated['partner_id'] ?? null,
                'client_notes' => $validated['client_notes'] ?? null,
                'user_notes' => $validated['employee_notes'] ?? null,
                'status' => 'pending'
            ]);

            //store this action on the action_performed_on_order table
            $reason = 'Commande créée par: ';
            $authenticatedUser = $authEntityService->getAuthenticatedUser();

            if ($authenticatedUser instanceof Partner) {
                $reason = 'Partenaire - ' . $authenticatedUser->name;
            } elseif ($authenticatedUser instanceof Employee) {
                $reason = 'Employé - ' . $authenticatedUser->employee_name;
            } else {
                $reason = 'Admin';
            }

            ActionOnOrder::create([
                'user_id' => $user->id,
                'order_id' => $order->id,
                'action' => 'crée',
                'details' => $reason,
                'action_performed_at' => now()
            ]);

            Cache::forget('pending_orders');
            $this->notificationService->sendNotification(
                'Nouvelle commande',
                "Une nouvelle commande à été crée",
                "/livreur.html",
                $this->notificationService->getDeliveryDriversTokens()
            );

            return response()->json($order, 201);
        });
    }

    public function show(string $id, AuthEntityService $authEntityService)
    {
        try {
            $order = Order::with(['client', 'Partner', 'user', 'deliveryPerson', 'cancellationCause'])
                ->findOrFail($id);

            if ($order->status === 'canceled') {
                $order->cancellation_cause = CanceledOrdersCause::where('order_id', $order->id)->value('cause');
            }

            // Use AuthEntityService to get the name of the user who created the order
            $orderCreator = $authEntityService->getUserById($order->user_id);
            if ($orderCreator instanceof Partner) {
                $userName = 'Partenaire - ' . $orderCreator->name;
                $userPhone = $orderCreator->phone;
            } elseif ($orderCreator instanceof Employee) {
                $userName = 'Employé - ' . $orderCreator->employee_name;
                $userPhone = $orderCreator->employee_phone;
            } else {
                $userName = 'Admin';
            }


            $order->user->name = $userName;
            $order->user->phone = $userPhone ?? null;

            return response()->json($order);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => ['nullable', Rule::in(['pending', 'accepted', 'delivered', 'canceled'])],
            'delivery_person_id' => 'nullable|exists:delivery_person,id',
            'driver_notes' => 'nullable|string|max:255',
            'client_notes' => 'nullable|string|max:255',
            'employee_notes' => 'nullable|string|max:255',
            'request' => 'nullable|string|max:255',
            'partner_id' => 'nullable|exists:partner,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $validated = $validator->validated();

        try {
            return DB::transaction(function () use ($id, $validated, $request) {
                $order = Order::lockForUpdate()->findOrFail($id);
                $originalStatus = $order->status;
                if ($request->has('status')) {
                    $this->validateStatusTransition($originalStatus, $validated['status']);
                    $this->handleStatusChange($order, $validated);
                }
                $order->update([
                    'delivery_person_notes' => $validated['driver_notes'] ?? $order->delivery_person_notes,
                    'client_notes' => $validated['client_notes'] ?? $order->client_notes,
                    'employee_notes' => $validated['employee_notes'] ?? $order->employee_notes,
                    'request' => $validated['request'] ?? $order->request,
                    'partner_id' => $validated['partner_id'] ?? null,
                ]);

                Cache::forget('pending_orders');
                $this->notificationService->sendNotification(
                    'Commande mis à jour',
                    "La commande #{$order->id} a été mis à jour",
                    "/orders.html",
                    $this->notificationService->getAdminsTokens()
                );
                return response()->json($order);
            });
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage(), 'line' => $th->getLine()], 500);
        }
    }

    public function acceptOrder(Request $request, string $id, AuthEntityService $authEntityService)
    {
        try {
            $user = Auth::guard('sanctum')->user();

            return DB::transaction(function () use ($id, $user, $authEntityService) {
                // Get the delivery person associated with the authenticated user
                $deliveryPerson = DeliveryPerson::where('user_id', $user->id)
                    ->firstOrFail();

                $order = Order::with('client')->findOrFail($id);
                $acceptedOrder = $order->toArray();
                if ($order->user && $order->user->role === 'partner') {
                    $acceptedOrder['partner'] = $authEntityService->getUserById($order->user_id);
                }

                // Pass the delivery person
                $this->handleOrderAcceptance($order, $deliveryPerson);

                Cache::forget('pending_orders');
                return response()->json([
                    'order' => $acceptedOrder,
                ]);
            });
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $order = Order::findOrFail($id);

            if (in_array($order->status, ['delivered', 'canceled'])) {
                return response()->json(['message' => 'Cannot delete completed orders'], 403);
            }

            if ($order->task || $order->cancellationCause) {
                // Delete the order's related tasks, cancellation causes, and actions if they exist.
                $order->task()->delete();
                $order->cancellationCause()->delete();
                $order->actionsOnOrder()->delete();
            }

            $order->delete();

            $this->notificationService->sendNotification(
                'Commande supprimée',
                "La commande #{$order->id} a été supprimée",
                "/orders.html",
                $this->notificationService->getAdminsTokens()
            );

            Cache::forget('pending_orders');

            return response()->json(null, 204);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Order not found'], 404);
        } catch (\Throwable $th) {
            return response()->json(['error' => 'Failed to delete order: ' . $th->getMessage()], 500);
        }
    }

    private function validateStatusTransition(string $currentStatus, string $newStatus)
    {
        $allowedTransitions = [
            'pending' => ['accepted', 'canceled'],
            'accepted' => ['delivered', 'canceled', 'pending'],
            'delivered' => [],
            'canceled' => []
        ];

        if (!in_array($newStatus, $allowedTransitions[$currentStatus])) {
            abort(422, 'Invalid status transition');
        }
    }

    private function handleStatusChange(Order $order, array $data)
    {
        switch ($data['status']) {
            case 'accepted':
                $this->handleOrderAcceptance($order, $data['delivery_person_id']);
                break;
            case 'delivered':
                $this->handleOrderDelivery($order);
                break;
            case 'canceled':
                $this->handleOrderCancellation($order, $data['driver_notes'] ?? '');
                break;
        }
    }

    private function handleOrderAcceptance(Order $order, DeliveryPerson $deliveryPerson)
    {
        // $deliveryPerson = DeliveryPerson::where('id', $deliveryPersonId)
        //     ->where('is_available', true)
        //     ->firstOrFail();

        $order->update([
            'status' => 'accepted',
            'delivery_person_id' => $deliveryPerson->id,
            // 'accepted_at' => now()
        ]);

        ActionOnOrder::create([
            'user_id' => $deliveryPerson->user_id,
            'order_id' => $order->id,
            'action' => 'acceptée',
            'details' => 'Commande acceptée par le livreur: ' . $deliveryPerson->delivery_person_name,
            'action_performed_at' => now()
        ]);

        // $deliveryPerson->update(['is_available' => false]);
        // $this->notifyOrderAccepted($order, $deliveryPerson);
    }

    private function handleOrderDelivery(Order $order)
    {
        $order->update([
            'status' => 'delivered',
            'delivered_canceled_at' => now()
        ]);
        $order->deliveryPerson->decrement('balance', 3.00);
        if ($order->deliveryPerson->balance <= 3) {
            $order->deliveryPerson->update(['is_available' => false]);
        }

        ActionOnOrder::create([
            'user_id' => $order->deliveryPerson->user->id,
            'order_id' => $order->id,
            'action' => 'livrée',
            'details' => 'Commande livrée par le livreur: ' . $order->deliveryPerson->delivery_person_name,
            'action_performed_at' => now()
        ]);

        //check to see if the client is associated with a delievery driver and the delivery driver is not the same as the one who delivered the order
        $orderCreator = $this->authEntityService->getUserById($order->client->added_by);
        if ($orderCreator instanceof DeliveryPerson) {
            if ($orderCreator->id !== $order->delivery_person_id) {
                //if the client belongs to a delivery driver and the delivery driver is not the same as the one who delivered the order, increment his balance by 1.00
                $orderCreator->increment('balance', 1.00);
            }
        }
        // $this->notifyOrderDelivered($order);
    }

    private function handleOrderCancellation(Order $order, string $reason)
    {
        $order->update([
            'status' => 'canceled',
            'delivered_canceled_at' => now()
        ]);

        if ($order->delivery_person_id) {
            $order->deliveryPerson->update(['is_available' => true]);
        }

        // CanceledOrdersCause::create([
        //     'order_id' => $order->id,
        //     'cause' => $reason
        // ]);

        ActionOnOrder::create([
            'user_id' => $order->delivery_person_id,
            'order_id' => $order->id,
            'action' => 'annulée',
            'details' => 'Commande annulée pour la raison: ' . $reason,
            'action_performed_at' => now()
        ]);

        $this->createCancellationTask($order);

        $this->notificationService->sendNotification(
            'Commande annulée',
            "La commande #{$order->id} a été annulée",
            "/orders.html",
            $this->notificationService->getAdminsTokens()
        );
    }

    private function createCancellationTask(Order $order)
    {
        $assignedEmployee = $order->user->role == 'employee' ? $order->user_id : Employee::where('status', 'active')->inRandomOrder()->first()->id;
        Task::create([
            'order_id' => $order->id,
            'assigned_employee_id' => $assignedEmployee,
            'task_status' => 'started'
        ]);
        $employeeNotifToken = DB::table('users_tokens')
            ->join('employee', 'users_tokens.user_id', '=', 'employee.user_id')
            ->where('employee.id', $assignedEmployee);
        $this->notificationService->sendNotification(
            'Nouvelle tâche de suivi',
            "Une nouvelle tâche de suivi a été créée pour la commande #{$order->id}",
            "/tasks.html",
            $employeeNotifToken
        );
    }

    /**
     * Reject an order
     */
    // This method is used to reject an order by the delivery person
    // It updates the order status to 'pending' and sets the delivery person ID to null
    // and records the rejection in the delivery driver activity
    public function rejectOrder(Request $request, string $id)
    {
        try {
            $user = Auth::guard('sanctum')->user();

            return DB::transaction(function () use ($id, $user) {
                // Get authenticated delivery person
                $deliveryPerson = DeliveryPerson::where('user_id', $user->id)
                    ->firstOrFail();

                // Validate order ownership and status
                $order = Order::where('id', $id)
                    ->where('delivery_person_id', $deliveryPerson->id)
                    ->where('status', 'accepted')
                    ->firstOrFail();

                // Update order status
                $order->update([
                    'status' => 'pending',
                    'delivery_person_id' => null,
                    // 'delivered_canceled_at' => null,
                    // 'accepted_at' => null
                ]);

                // Update delivery person availability
                $deliveryPerson->update(['is_available' => true]);

                // Record rejection in the actionOnOrder table
                ActionOnOrder::create([
                    'user_id' => $deliveryPerson->user_id,
                    'order_id' => $order->id,
                    'action' => 'rejetée',
                    'details' => 'Commande rejetée par le livreur: ' . $deliveryPerson->delivery_person_name,
                    'action_performed_at' => now()
                ]);

                Cache::forget('pending_orders');

                $this->notificationService->sendNotification(
                    'Commande rejetée',
                    "La commande #{$order->id} a été rejetée par le livreur: {$deliveryPerson->delivery_person_name}",
                    "/orders.html",
                    $this->notificationService->getAdminsTokens()
                );

                return response()->json([
                    'message' => 'Order rejected successfully',
                    'order' => $order
                ]);
            });
        } catch (\Throwable $th) {
            return response()->json([
                'error' => 'Rejection failed: ' . $th->getMessage()
            ], 400);
        }
    }
}