<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class AdminController extends Controller
{
    /**
     * Show the admin dashboard index.
     */
    public function index(): View
    {
        return view('private.index');
    }


}