<?php

namespace App\Http\Controllers;

use App\Desk\DeskJobs;
use App\Desk\JobState;
use Illuminate\Http\Request;

/** The CrowdMerch Desk (docs/desk.md): every job from CMAU_DEV in one list, each with its next step. */
class DeskController extends Controller
{
    public const TABS = ['needs' => 'Needs me', 'quotes' => 'Quotes', 'orders' => 'Orders', 'production' => 'Production', 'waiting' => 'Waiting on client', 'done' => 'Done this week'];

    public function index(Request $request, DeskJobs $desk)
    {
        $tab = array_key_exists((string) $request->query('tab'), self::TABS) ? (string) $request->query('tab') : 'needs';
        $jobs = $desk->all();
        $counts = collect(JobState::BUCKETS)->map(fn ($label, $key) => ['label' => $label, 'n' => $jobs->where('bucket', $key)->count(),
            'hot' => $jobs->where('bucket', $key)->where('needs', true)->isNotEmpty()]);

        return view('desk.index', [
            'tab' => $tab,
            'tabs' => collect(self::TABS)->map(fn ($label, $key) => ['label' => $label, 'n' => $desk->filter($jobs, $key)->count()]),
            'jobs' => $desk->filter($jobs, $tab),
            'counts' => $counts,
        ]);
    }
}
