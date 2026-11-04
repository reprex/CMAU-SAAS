<?php

namespace App\Http\Controllers;

use App\Catalogue\VetQueue;

/** The one Vet screen: every catalogue and website exception that still needs a person (docs/vet.md). */
class VetController extends Controller
{
    public function index(VetQueue $vet)
    {
        return view('vet.index', ['sections' => $vet->sections(), 'isAdmin' => auth()->user()->isAdmin(), 'canEdit' => auth()->user()->canEdit()]);
    }
}
