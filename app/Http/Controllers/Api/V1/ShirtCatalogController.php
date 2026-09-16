<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ShirtColorRequest;
use App\Http\Requests\Api\V1\ShirtLogoRequest;
use App\Http\Requests\Api\V1\ShirtTemplateRequest;
use App\Models\ShirtColor;
use App\Models\ShirtLogo;
use App\Models\ShirtTemplate;
use App\Services\ShirtCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The colours, logos and shirt templates the live preview is built from.
 *
 * DELETE deactivates rather than removes: milestones reference these rows
 * historically, and what was ordered last year must still read back.
 */
class ShirtCatalogController extends Controller
{
    public function __construct(
        private readonly ShirtCatalogService $catalogService
    ) {
    }

    /**
     * The single catalogue read: everything the preview UI needs to bootstrap,
     * in one call.
     *
     * Active rows only by default. The management screens pass
     * ?include_inactive=1 to also see what has been deactivated.
     */
    public function catalog(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->catalogService->catalog(
                includeInactive: $request->boolean('include_inactive')
            ),
        ]);
    }

    // -------------------------------------------------------------------------
    // Colors
    // -------------------------------------------------------------------------

    public function storeColor(ShirtColorRequest $request): JsonResponse
    {
        return response()->json(
            ['data' => $this->catalogService->createColor($request->validated())],
            201
        );
    }

    public function updateColor(ShirtColorRequest $request, ShirtColor $color): JsonResponse
    {
        return response()->json(
            ['data' => $this->catalogService->updateColor($color, $request->validated())]
        );
    }

    public function destroyColor(ShirtColor $color): JsonResponse
    {
        $this->catalogService->deactivateColor($color);

        return response()->json(null, 204);
    }

    // -------------------------------------------------------------------------
    // Logos
    // -------------------------------------------------------------------------


    public function storeLogo(ShirtLogoRequest $request): JsonResponse
    {
        return response()->json(
            ['data' => $this->catalogService->createLogo($request->validated(), $request->file('file'))],
            201
        );
    }

    public function updateLogo(ShirtLogoRequest $request, ShirtLogo $logo): JsonResponse
    {
        return response()->json([
            'data' => $this->catalogService->updateLogo(
                $logo,
                $request->validated(),
                $request->file('file')
            ),
        ]);
    }

    public function destroyLogo(ShirtLogo $logo): JsonResponse
    {
        $this->catalogService->deactivateLogo($logo);

        return response()->json(null, 204);
    }

    // -------------------------------------------------------------------------
    // Templates
    // -------------------------------------------------------------------------


    public function storeTemplate(ShirtTemplateRequest $request): JsonResponse
    {
        return response()->json(
            ['data' => $this->catalogService->createTemplate($request->validated(), $request->file('svg'))],
            201
        );
    }

    public function updateTemplate(ShirtTemplateRequest $request, ShirtTemplate $template): JsonResponse
    {
        return response()->json([
            'data' => $this->catalogService->updateTemplate(
                $template,
                $request->validated(),
                $request->file('svg')
            ),
        ]);
    }

    public function destroyTemplate(ShirtTemplate $template): JsonResponse
    {
        $this->catalogService->deactivateTemplate($template);

        return response()->json(null, 204);
    }
}
