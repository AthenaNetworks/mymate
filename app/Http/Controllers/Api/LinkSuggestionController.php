<?php

namespace App\Http\Controllers\Api;

use App\Actions\Links\AcceptLinkSuggestion;
use App\Actions\Links\RecordNeighbors;
use App\Http\Controllers\Controller;
use App\Http\Resources\LinkResource;
use App\Http\Resources\LinkSuggestionResource;
use App\Models\LinkSuggestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The suggested-links review queue (GitHub #85). Accepting draws the link, ignoring keeps it out of
 * the queue for good (it stays ignored when it's seen again), and restore undoes an ignore. The
 * writes are admin-only through RestrictWritesToAdmins, like the discovery candidates.
 */
class LinkSuggestionController extends Controller
{
    /** Optional `?status=new|ignored` (default new). Ones a link drawn since covers are hidden. */
    public function index(Request $request, RecordNeighbors $neighbors): AnonymousResourceCollection
    {
        $status = $request->query('status') === 'ignored' ? 'ignored' : 'new';
        $rows = LinkSuggestion::where('status', $status)
            ->with(['aDevice:id,name,alias', 'bDevice:id,name,alias', 'aInterface:id,name', 'bInterface:id,name'])
            ->latest('last_seen_at')->get()
            ->reject(fn (LinkSuggestion $s) => $neighbors->covered($s->a_device_id, $s->a_interface_id, $s->b_device_id, $s->b_interface_id))
            ->values();

        return LinkSuggestionResource::collection($rows);
    }

    public function accept(LinkSuggestion $suggestion, AcceptLinkSuggestion $accept): JsonResponse
    {
        $link = $accept($suggestion);

        return $link === null
            ? response()->json(['data' => null])
            : (new LinkResource($link))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /** Accept every new suggestion at once. */
    public function acceptAll(AcceptLinkSuggestion $accept): JsonResponse
    {
        $created = 0;
        foreach (LinkSuggestion::where('status', 'new')->orderBy('id')->get() as $s) {
            $s = $s->fresh(); // an earlier accept may have removed it as covered
            if ($s !== null && $accept($s) !== null) {
                $created++;
            }
        }

        return response()->json(['data' => ['created' => $created]]);
    }

    public function ignore(LinkSuggestion $suggestion): LinkSuggestionResource
    {
        $suggestion->update(['status' => 'ignored']);

        return new LinkSuggestionResource($suggestion->load(['aDevice', 'bDevice', 'aInterface', 'bInterface']));
    }

    public function restore(LinkSuggestion $suggestion): LinkSuggestionResource
    {
        abort_if($suggestion->status !== 'ignored', Response::HTTP_UNPROCESSABLE_ENTITY, 'Only an ignored suggestion can be restored.');
        $suggestion->update(['status' => 'new']);

        return new LinkSuggestionResource($suggestion->load(['aDevice', 'bDevice', 'aInterface', 'bInterface']));
    }
}
