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

    /**
     * Retourne la liste des doublons dans la base de données clients.
     * Détecte les doublons principalement sur le courriel, mais peut aussi considérer
     * le téléphone et le nom d'entreprise comme critères secondaires.
     */
    public function duplicates(Request $request)
    {
        // Détection des doublons par courriel (critère principal)
        $emailDuplicates = Client::selectRaw('courriel, COUNT(*) as count')
            ->whereNotNull('courriel')
            ->where('courriel', '!=', '')
            ->groupBy('courriel')
            ->having('count', '>', 1)
            ->orderBy('count', 'desc')
            ->get();

        // Détection des doublons par téléphone (critère secondaire)
        $phoneDuplicates = Client::selectRaw('telephone, COUNT(*) as count')
            ->whereNotNull('telephone')
            ->where('telephone', '!=', '')
            ->groupBy('telephone')
            ->having('count', '>', 1)
            ->orderBy('count', 'desc')
            ->get();

        // Pour chaque groupe de doublons, récupérer les détails des clients
        $emailDuplicateDetails = [];
        foreach ($emailDuplicates as $duplicate) {
            $clients = Client::where('courriel', $duplicate->courriel)
                ->orderBy('id')
                ->get();

            $emailDuplicateDetails[] = [
                'criteria' => 'courriel',
                'value' => $duplicate->courriel,
                'count' => $duplicate->count,
                'clients' => $clients
            ];
        }

        $phoneDuplicateDetails = [];
        foreach ($phoneDuplicates as $duplicate) {
            $clients = Client::where('telephone', $duplicate->telephone)
                ->orderBy('id')
                ->get();

            $phoneDuplicateDetails[] = [
                'criteria' => 'telephone',
                'value' => $duplicate->telephone,
                'count' => $duplicate->count,
                'clients' => $clients
            ];
        }

        // Statistiques générales
        $totalEmailDuplicates = $emailDuplicates->sum('count');
        $totalPhoneDuplicates = $phoneDuplicates->sum('count');
        $totalUniqueDuplicateGroups = $emailDuplicates->count() + $phoneDuplicates->count();

        return response()->json([
            'summary' => [
                'total_duplicate_groups' => $totalUniqueDuplicateGroups,
                'total_email_duplicates' => $totalEmailDuplicates,
                'total_phone_duplicates' => $totalPhoneDuplicates,
                'total_overall_duplicates' => $totalEmailDuplicates + $totalPhoneDuplicates
            ],
            'duplicates' => [
                'by_email' => $emailDuplicateDetails,
                'by_phone' => $phoneDuplicateDetails
            ]
        ]);
    }
}
