<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CardSet;
use App\Models\CardTemplate;
use Spatie\Browsershot\Browsershot;

class ChecklistController extends Controller
{
    public function downloadPdf($code)
    {
        $set = CardSet::where('code', $code)->firstOrFail(); 
        $cards = CardTemplate::where('card_set_id', $set->id)
                             ->orderBy('card_number')
                             ->get();

        $ownedCards = [];
        
        // 🚀 TRAMPA 1 RESUELTA: Le decimos a Laravel que busque el token de la API
        $user = request()->user('sanctum'); 
        $isLoggedIn = $user !== null;

        if ($isLoggedIn) {
            // 🚀 NUEVO: Agrupamos por carta y sumamos la cantidad total
            $ownedCards = \App\Models\UserCard::where('user_id', $user->id)
                            ->selectRaw('card_template_id, SUM(quantity) as total_quantity')
                            ->groupBy('card_template_id')
                            ->pluck('total_quantity', 'card_template_id')
                            ->toArray();
        }

        $html = view('pdf.checklist', [
            'set' => $set,
            'cards' => $cards,
            'ownedCards' => $ownedCards,
            'isLoggedIn' => $isLoggedIn
        ])->render();

        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        
        $chromePath = $isWindows 
            ? 'C:\Program Files\Google\Chrome\Application\chrome.exe' 
            : '/usr/bin/google-chrome'; 

        $pdfContent = Browsershot::html($html)
            ->setChromePath($chromePath)
            ->noSandbox()
            ->format('A4')
            ->landscape()
            ->margins(10, 10, 10, 10)
            ->pdf();

        return response()->streamDownload(function () use ($pdfContent) {
            echo $pdfContent;
        }, "Checklist_{$set->code}.pdf", [
            'Content-Type' => 'application/pdf',
        ]);
    }
}