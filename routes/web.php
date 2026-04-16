<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

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
