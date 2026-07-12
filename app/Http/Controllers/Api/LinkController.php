<?php

namespace App\Http\Controllers\Api;

use App\Actions\Links\CreateLink;
use App\Actions\Links\DeleteLink;
use App\Actions\Links\UpdateLink;
use App\Http\Controllers\Controller;
use App\Http\Requests\Link\StoreLinkRequest;
use App\Http\Requests\Link\UpdateLinkRequest;
use App\Http\Resources\LinkResource;
use App\Models\Link;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class LinkController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return LinkResource::collection(Link::with('aInterface', 'bInterface')->get());
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
