<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\SAPService;

class AuthController extends Controller
{
    protected $sapService;

    public function __construct(SAPService $sapService)
    {
        $this->sapService = $sapService;
    }

    public function showLoginForm()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'company'  => 'required',
            'username' => 'required',
            'password' => 'required',
        ]);

        $response = $this->sapService->login($request->username, $request->password, $request->company);

        if ($response['success']) {
            session([
                'sap_company_db' => $request->company,
                'b1_token'       => $response['B1SESSION']
            ]);

            if ($response['ROUTEID']) {
                session(['route_id' => $response['ROUTEID']]);
            }

            return redirect()->intended('/bankpages');
        }

        return back()->withErrors(['username' => $response['message']]);
    }

    public function logout(Request $request)
    {
        $request->session()->forget(['b1_token', 'route_id', 'sap_company_db']);
        return redirect()->route('login');
    }
}
