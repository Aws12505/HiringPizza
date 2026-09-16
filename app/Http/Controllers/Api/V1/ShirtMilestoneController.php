<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ShirtMilestoneCancelRequest;
use App\Http\Requests\Api\V1\ShirtMilestoneDeliverRequest;
use App\Http\Requests\Api\V1\ShirtMilestoneDeliveryDateRequest;
use App\Http\Requests\Api\V1\ShirtMilestoneEntryRequest;
use App\Http\Requests\Api\V1\ShirtMilestoneIndexRequest;
use App\Http\Requests\Api\V1\ShirtMilestoneOrderRequest;
use App\Http\Requests\Api\V1\ShirtMilestoneStoreRequest;
use App\Models\Employee;
use App\Models\EmployeeShirtMilestone;
use App\Services\ShirtMilestoneWorkflowService;
use Illuminate\Http\JsonResponse;

class ShirtMilestoneController extends Controller
{
    public function __construct(
        private readonly ShirtMilestoneWorkflowService $workflowService
    ) {
    }

    // -------------------------------------------------------------------------
    // Store-scoped — the store manager's queue
    // -------------------------------------------------------------------------

    public function index(ShirtMilestoneIndexRequest $request, string $storeNumber): JsonResponse
    {
        $store = $this->workflowService->resolveStoreByNumber($storeNumber);

        return response()->json(
            $this->workflowService->indexForStore($store, $request->validated())
        );
    }

    public function show(string $storeNumber, EmployeeShirtMilestone $shirtMilestone): JsonResponse
    {
        $store = $this->workflowService->resolveStoreByNumber($storeNumber);

        if ($shirtMilestone->store_id !== $store->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(['data' => $this->workflowService->load($shirtMilestone)]);
    }

    /**
     * Create and fill in one call — for giving someone a shirt without waiting
     * for a month to come around.
     */
    public function store(ShirtMilestoneStoreRequest $request, string $storeNumber): JsonResponse
    {
        $store = $this->workflowService->resolveStoreByNumber($storeNumber);
        $milestone = $this->workflowService->createManual($store, $request->validated());

        return response()->json(['data' => $milestone], 201);
    }

    public function entry(
        ShirtMilestoneEntryRequest $request,
        string $storeNumber,
        EmployeeShirtMilestone $shirtMilestone
    ): JsonResponse {
        $store = $this->workflowService->resolveStoreByNumber($storeNumber);
        $milestone = $this->workflowService->submitEntry($store, $shirtMilestone, $request->validated());

        return response()->json(['data' => $milestone]);
    }

    /**
     * How many shirts this employee has received so far, and each one's detail.
     */
    public function employeeHistory(string $storeNumber, Employee $employee): JsonResponse
    {
        $store = $this->workflowService->resolveStoreByNumber($storeNumber);

        $latestStoreId = $employee->stores()
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->value('store_id');

        if ($latestStoreId === null || (int) $latestStoreId !== $store->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $employee->loadMissing('obsession');

        return response()->json(['data' => $this->workflowService->historyForEmployee($employee)]);
    }

    // -------------------------------------------------------------------------
    // Global — the fulfilment queue
    // -------------------------------------------------------------------------

    public function indexGlobal(ShirtMilestoneIndexRequest $request): JsonResponse
    {
        return response()->json(
            $this->workflowService->indexGlobal($request->validated())
        );
    }

    public function showGlobal(EmployeeShirtMilestone $shirtMilestone): JsonResponse
    {
        return response()->json(['data' => $this->workflowService->load($shirtMilestone)]);
    }

    public function order(
        ShirtMilestoneOrderRequest $request,
        EmployeeShirtMilestone $shirtMilestone
    ): JsonResponse {
        return response()->json([
            'data' => $this->workflowService->markOrdered($shirtMilestone, $request->validated()),
        ]);
    }

    public function updateDeliveryDate(
        ShirtMilestoneDeliveryDateRequest $request,
        EmployeeShirtMilestone $shirtMilestone
    ): JsonResponse {
        return response()->json([
            'data' => $this->workflowService->updateDeliveryDate($shirtMilestone, $request->validated()),
        ]);
    }

    public function deliver(
        ShirtMilestoneDeliverRequest $request,
        EmployeeShirtMilestone $shirtMilestone
    ): JsonResponse {
        return response()->json([
            'data' => $this->workflowService->markDelivered($shirtMilestone, $request->validated()),
        ]);
    }

    public function cancel(
        ShirtMilestoneCancelRequest $request,
        EmployeeShirtMilestone $shirtMilestone
    ): JsonResponse {
        return response()->json([
            'data' => $this->workflowService->cancel($shirtMilestone, $request->validated()),
        ]);
    }
}
