<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportRequest;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function listing(ReportRequest $request, Listing $listing): RedirectResponse
    {
        abort_unless(Listing::published()->whereKey($listing->id)->exists(), 404);
        abort_if($listing->user_id === $request->user()->id, 403);

        return $this->store($request, 'listing', $listing->id);
    }

    public function user(ReportRequest $request, User $user): RedirectResponse
    {
        abort_if($user->id === $request->user()->id, 403);

        return $this->store($request, 'user', $user->id);
    }

    private function store(ReportRequest $request, string $type, int $id): RedirectResponse
    {
        DB::table('reports')->insertOrIgnore(['ip_address' => $request->ip(), 'reporter_id' => $request->user()->id, 'target_type' => $type, 'target_id' => $id, 'reason' => $request->validated('reason'), 'details' => $request->validated('details'), 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('report_received', true);
    }
}
