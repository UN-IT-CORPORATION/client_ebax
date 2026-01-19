<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    /**
     * Retourne la liste paginée des clients (500 par page).
     */
    public function index(Request $request)
    {
        $perPage = 500;

        $clients = Client::query()
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json($clients);
    }
}
