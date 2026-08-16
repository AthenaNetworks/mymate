<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\GraphSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The house default graph style (fill / stacked / axis colour). `show` is readable by any operator
 * (the Graphs page needs it to resolve a graph that inherits the default); `update` is admin-only
 * (gated on the route). Each graph can still override these per-graph in its own config.style.
 */
class GraphSettingController extends Controller
{
    public function show(GraphSettings $settings): JsonResponse
    {
        return response()->json(['data' => $settings->style()]);
    }

    public function update(Request $request, GraphSettings $settings): JsonResponse
    {
        $data = $request->validate([
            'fill' => ['required', 'boolean'],
            'stacked' => ['required', 'boolean'],
            'color_mode' => ['required', 'in:group,series'],
            'palette' => ['required', 'array', 'min:1', 'max:32'],
            'palette.*' => ['string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        return response()->json(['data' => $settings->setStyle($data)]);
    }
}
