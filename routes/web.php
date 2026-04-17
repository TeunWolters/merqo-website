<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Sitemap
|--------------------------------------------------------------------------
*/

Route::get('/sitemap.xml', function () {
    $content = view('sitemap.xml')->render();
    return response($content, 200, [
        'Content-Type' => 'application/xml',
    ]);
});

/*
|--------------------------------------------------------------------------
| Blog 301 redirects (gewijzigde slugs)
|--------------------------------------------------------------------------
*/

Route::redirect('/blog/google-bedrijfsprofiel-optimaliseren-lokaal-gevonden', '/blog/google-mijn-bedrijf-optimaliseren', 301);
Route::redirect('/blog/instagram-voor-bedrijven-volgers-die-converteren',     '/blog/instagram-voor-bedrijven', 301);
Route::redirect('/blog/goed-logo-laten-ontwerpen-5-principes',               '/blog/logo-laten-ontwerpen', 301);
Route::redirect('/blog/waarom-merkidentiteit-meer-is-dan-een-logo',          '/blog/merkidentiteit-meer-dan-een-logo', 301);

/*
|--------------------------------------------------------------------------
| Blog categorie routes
|--------------------------------------------------------------------------
*/

Route::statamic('/blog/{category_slug}', 'blog/category', ['load' => '/blog'])
    ->where('category_slug', 'branding|websites|marketing|strategie|tips|drukwerk');

/*
|--------------------------------------------------------------------------
| Portaal routes
|--------------------------------------------------------------------------
*/

// Login handler
Route::post('/portaal/auth', function (Request $request) {
    $credentials = $request->validate([
        'email'    => ['required', 'email'],
        'password' => ['required'],
    ]);

    if (Auth::attempt($credentials, true)) {
        $request->session()->regenerate();
        return response()->json(['ok' => true]);
    }

    return response()->json(['message' => 'Onjuiste inloggegevens. Probeer het opnieuw.'], 401);
});

// Dashboard — alleen toegankelijk als ingelogd
Route::get('/portaal/dashboard', function () {
    if (! Auth::check()) {
        return redirect('/portaal');
    }
    return view('portaal-dashboard');
})->name('portaal.dashboard');

// Uitloggen
Route::get('/portaal/logout', function (Request $request) {
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();
    return redirect('/');
});

/*
|--------------------------------------------------------------------------
| Scan form handler (Statamic form fallback)
|--------------------------------------------------------------------------
*/
Route::post('/scan/verstuur', function (Request $request) {
    $request->merge([
        'website'     => $request->website     ?: null,
        'uitdaging'   => $request->uitdaging   ?: null,
        'toelichting' => $request->toelichting ?: null,
    ]);

    $data = $request->validate([
        'bedrijfsnaam' => ['required', 'string', 'max:255'],
        'naam'         => ['required', 'string', 'max:255'],
        'email'        => ['required', 'email'],
        'website'      => ['nullable', 'string', 'max:255'],
        'uitdaging'    => ['required', 'string'],
        'toelichting'  => ['nullable', 'string', 'max:2000'],
    ]);

    $slug = \Illuminate\Support\Str::slug($data['bedrijfsnaam']) . '-' . time();

    \Statamic\Facades\Entry::make()
        ->collection('scan-aanvragen')
        ->slug($slug)
        ->data([
            'title'       => $data['bedrijfsnaam'],
            'naam'        => $data['naam'],
            'email'       => $data['email'],
            'website'     => $data['website'] ?? '',
            'uitdaging'   => $data['uitdaging'],
            'toelichting' => $data['toelichting'] ?? '',
            'status'      => 'nieuw',
            'date'        => now()->toDateString(),
        ])
        ->save();

    return response()->json(['ok' => true]);
});

/*
|--------------------------------------------------------------------------
| Contact form handler
|--------------------------------------------------------------------------
*/
Route::post('/contact/verstuur', function (Request $request) {
    $data = $request->validate([
        'name'    => ['required', 'string', 'max:255'],
        'email'   => ['required', 'email'],
        'message' => ['required', 'string'],
        'company' => ['nullable', 'string', 'max:255'],
        'service' => ['nullable', 'string', 'max:255'],
        'budget'  => ['nullable', 'string', 'max:255'],
    ]);

    $slug = \Illuminate\Support\Str::slug($data['name']) . '-' . time();

    \Statamic\Facades\Entry::make()
        ->collection('contact-aanvragen')
        ->slug($slug)
        ->data([
            'title'   => $data['name'],
            'email'   => $data['email'],
            'message' => $data['message'],
            'company' => $data['company'] ?? '',
            'service' => $data['service'] ?? '',
            'budget'  => $data['budget'] ?? '',
            'status'  => 'nieuw',
            'date'    => now()->toDateString(),
        ])
        ->save();

    // Mail notificatie naar Luuk
    $name    = $data['name'];
    $email   = $data['email'];
    $company = $data['company'] ?? 'Niet ingevuld';
    $service = $data['service'] ?? 'Niet ingevuld';
    $budget  = $data['budget']  ?? 'Niet ingevuld';
    $message = $data['message'];

    Mail::raw(
        "Nieuw contactformulier via merqo.nl\n\n" .
        "Naam:     {$name}\n" .
        "E-mail:   {$email}\n" .
        "Bedrijf:  {$company}\n" .
        "Dienst:   {$service}\n" .
        "Budget:   {$budget}\n\n" .
        "Bericht:\n{$message}\n",
        function ($mail) use ($name, $email) {
            $mail->to('info@merqo.nl')
                 ->replyTo($email, $name)
                 ->subject("Nieuw bericht van {$name} via merqo.nl");
        }
    );

    return response()->json(['ok' => true]);
});

/*
|--------------------------------------------------------------------------
| AI Chatbot — Claude (Anthropic)
|--------------------------------------------------------------------------
*/
Route::post('/api/chat', function (Request $request) {
    $validated = $request->validate([
        'messages'          => ['required', 'array', 'min:1', 'max:30'],
        'messages.*.role'   => ['required', 'in:user,assistant'],
        'messages.*.content'=> ['required', 'string', 'max:2000'],
    ]);

    $apiKey = config('services.anthropic.key');
    if (! $apiKey) {
        return response()->json(['error' => 'Chatbot is tijdelijk niet beschikbaar.'], 503);
    }

    $systemPrompt = <<<'SYSTEM'
Je bent Merqolino, de AI-assistent van Merqo. Je bent direct, eerlijk en altijd gericht op de volgende stap. Je praat zoals een slim Merqo-teamlid — geen chatbot-taal, geen gladde praatjes.

ABSOLUTE REGELS — GEEN UITZONDERINGEN
- Gebruik NOOIT sterretjes, bold, em dashes, streepjes als opsomming of andere opmaak.
- Schrijf gewone lopende zinnen. Geen lijstjes, geen bullets, geen markdown.
- Maximaal 2 zinnen per alinea, maximaal 2 alinea's totaal. Echt kort.
- Wil iemand meer weten? Verwijs naar de juiste pagina op de site, niet alles zelf uitleggen.
- Stel een gerichte tegenvraag als iemand vaag is.
- Sluit altijd af met een concrete vervolgstap als gewone zin.

PAGINAVERWIJZINGEN — gebruik deze als iemand meer wil weten
Alles over MaaS: merqo.nl/merqo-as-a-service
Branding: merqo.nl/diensten/branding
Websites: merqo.nl/diensten/websites
Marketing: merqo.nl/diensten/marketing
Strategie: merqo.nl/diensten/strategie
Projecten bekijken: merqo.nl/projecten
Gratis scan: merqo.nl/scan
Contact/offerte: merqo.nl/contact
- Altijd Nederlands.

CONVERSIEGERICHT DENKEN
Jouw doel is de bezoeker helpen én naar een gesprek of scan leiden. Doe dit natuurlijk, niet opdringerig. Gebruik deze aanpak:
- Iemand vraagt naar een dienst? Geef kort antwoord, stel dan een kwalificerende vraag ("Heb je al een huisstijl, of begin je van nul?") en sluit af met een CTA.
- Iemand twijfelt? Verlaag de drempel: wijs op de gratis scan of een vrijblijvend kennismakingsgesprek.
- Iemand is concreet klaar? Stuur direct naar /contact of WhatsApp.
- Prijs gevraagd voor losse projecten? Zeg eerlijk dat het maatwerk is en stel een vrijblijvend gesprek voor.

MERQO IN HET KORT
Creatief bureau in Hengelo (Twente). Opgericht door Luuk Scheffer. Helpt ondernemers groeien via branding, websites, marketing, strategie, fotografie en drukwerk. Klein betrokken team, geen grote bureaustructuur.

DIENSTEN (kort)
Branding: logo, huisstijl, merkstrategie. Websites: op maat, snel, conversiegerecht. Marketing: Google ads, social, content. Strategie: positionering en groeiplan. Fotografie/video: professioneel beeldmateriaal. Drukwerk: print in lijn met huisstijl.

MERQO AS A SERVICE (MaaS)
899 euro per maand. Vaste strateeg, alles inbegrepen (branding, website, marketing, content). Geen losse facturen. Maandelijks opzegbaar. Ideaal voor groeiende bedrijven zonder eigen marketeer.

GRATIS MERKENSCAN
15 minuten, geen verplichtingen, eerlijk inzicht in je merk. Link: merqo.nl/scan

CONTACT
/contact — info@merqo.nl — WhatsApp Luuk: +31 6 30 77 22 83
SYSTEM;

    $response = Http::timeout(30)
        ->withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])
        ->post('https://api.anthropic.com/v1/messages', [
            'model'      => 'claude-haiku-4-5',
            'max_tokens' => 800,
            'system'     => $systemPrompt,
            'messages'   => $validated['messages'],
        ]);

    if ($response->failed()) {
        return response()->json([
            'error' => 'API fout ' . $response->status() . ': ' . substr($response->body(), 0, 300),
        ], 500);
    }

    $data  = $response->json();
    $reply = $data['content'][0]['text'] ?? 'Geen antwoord ontvangen.';

    // Verwijder markdown-opmaak: bold, italic, em dashes, horizontale lijnen
    $reply = preg_replace('/\*\*(.+?)\*\*/', '$1', $reply);   // **bold**
    $reply = preg_replace('/\*(.+?)\*/', '$1', $reply);        // *italic*
    $reply = preg_replace('/_{1,2}(.+?)_{1,2}/', '$1', $reply); // _italic_ / __bold__
    $reply = str_replace('—', '-', $reply);                    // em dash
    $reply = preg_replace('/^[-*] /m', '', $reply);            // bullet points
    $reply = preg_replace('/^#{1,6} /m', '', $reply);          // headers
    $reply = preg_replace('/\[(.+?)\]\(.+?\)/', '$1', $reply); // [link](url)
    $reply = trim($reply);

    return response()->json(['reply' => $reply]);
})->middleware('throttle:30,1');
