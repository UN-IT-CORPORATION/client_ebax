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

    /**
     * Retourne la liste des doublons complets dans la base de données clients.
     * Détecte les doublons où nom_entreprise + téléphone + courriel sont identiques.
     */
    public function duplicatesComplets(Request $request)
    {
        // Détection des doublons par combinaison complète (nom_entreprise + téléphone + courriel)
        $duplicateGroups = Client::selectRaw('
                nom_entreprise,
                telephone,
                courriel,
                COUNT(*) as nombre_doublons,
                GROUP_CONCAT(id ORDER BY id) as client_ids
            ')
            ->whereNotNull('nom_entreprise')
            ->where('nom_entreprise', '!=', '')
            ->whereNotNull('telephone')
            ->where('telephone', '!=', '')
            ->whereNotNull('courriel')
            ->where('courriel', '!=', '')
            ->groupBy('nom_entreprise', 'telephone', 'courriel')
            ->having('nombre_doublons', '>', 1)
            ->orderBy('nombre_doublons', 'desc')
            ->get();

        // Récupérer les détails complets pour chaque groupe de doublons
        $doublonsDetails = [];
        $totalDoublons = 0;

        foreach ($duplicateGroups as $group) {
            $clients = Client::where('nom_entreprise', $group->nom_entreprise)
                ->where('telephone', $group->telephone)
                ->where('courriel', $group->courriel)
                ->orderBy('id')
                ->get();

            $doublonsDetails[] = [
                'nom_entreprise' => $group->nom_entreprise,
                'telephone' => $group->telephone,
                'courriel' => $group->courriel,
                'nombre_doublons' => $group->nombre_doublons,
                'clients' => $clients
            ];

            $totalDoublons += $group->nombre_doublons;
        }

        return response()->json([
            'resume' => [
                'nombre_groupes_doublons' => $duplicateGroups->count(),
                'total_doublons' => $totalDoublons,
                'moyenne_doublons_par_groupe' => $duplicateGroups->count() > 0 ? round($totalDoublons / $duplicateGroups->count(), 2) : 0
            ],
            'doublons' => $doublonsDetails
        ]);
    }
}
