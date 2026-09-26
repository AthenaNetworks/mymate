<?php

namespace App\Http\Controllers\Api;

use App\Actions\Links\CreateLink;
use App\Actions\Links\DeleteLink;
use App\Actions\Links\UpdateLink;
use App\Http\Controllers\Controller;
use App\Http\Requests\Link\StoreLinkRequest;
use App\Http\Requests\Link\UpdateLinkRequest;
use App\Http\Resources\LinkResource;
use App\Models\DeviceMapPosition;
use App\Models\Link;
use App\Models\Map;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class LinkController extends Controller
{
    /**
     * Links, optionally narrowed. The UI always narrows: `map_id` for the links drawn on one map
     * (both ends placed on it), `device_id` for the links touching one device. At 25k devices the
     * whole fleet's links with their interface rows is the next payload that falls over (GitHub
     * #22), so the bare form is only kept for API users who genuinely want everything.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'map_id' => ['sometimes', 'integer'],
            'device_id' => ['sometimes', 'integer'],
        ]);

        $query = Link::with('aInterface', 'bInterface');

        if ($request->filled('map_id')) {
            // Map visibility applies, so a map the operator can't see is a 404, not an empty list.
            $map = Map::findOrFail($request->integer('map_id'));
            $onMap = DeviceMapPosition::where('map_id', $map->id)->select('device_id');
            $query->whereIn('a_device_id', $onMap)->whereIn('b_device_id', clone $onMap);
        }
        if ($request->filled('device_id')) {
            $id = $request->integer('device_id');
            $query->where(fn ($q) => $q->where('a_device_id', $id)->orWhere('b_device_id', $id));
        }

        return LinkResource::collection($query->get());
    }

    public function store(StoreLinkRequest $request, CreateLink $createLink): JsonResponse
    {
        $link = $createLink($request->validated());

        return (new LinkResource($link))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateLinkRequest $request, Link $link, UpdateLink $updateLink): LinkResource
    {
        return new LinkResource($updateLink($link, $request->validated()));
    }

    public function destroy(Link $link, DeleteLink $deleteLink): Response
    {
        $deleteLink($link);

        return response()->noContent();
    }
}
