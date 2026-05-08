<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SAPService
{
    protected $baseUrl;
    protected $companyDb;

    public function __construct()
    {
        $this->baseUrl = env('SAP_SERVICE_LAYER_URL', 'https://192.168.0.100:50000/b1s/v1');
        // Priorizamos la base de datos de la sesión, si no existe usamos la del .env
        $this->companyDb = session('sap_company_db', env('SAP_COMPANY_DB', 'SBO_MANGO_BAJITO_PRODUCTIVA'));
    }

    public function login($username, $password, $companyDb = null)
    {
        $targetDb = $companyDb ?? $this->companyDb;

        try {
            $response = Http::withOptions(['verify' => false])
                ->post("{$this->baseUrl}/Login", [
                    'CompanyDB' => $targetDb,
                    'UserName' => $username,
                    'Password' => $password,
                ]);

            if ($response->successful()) {
                $cookies = $response->cookies();
                $b1Session = $cookies->getCookieByName('B1SESSION')->getValue();
                $routeId = $cookies->getCookieByName('ROUTEID') ? $cookies->getCookieByName('ROUTEID')->getValue() : null;

                return [
                    'success' => true,
                    'B1SESSION' => $b1Session,
                    'ROUTEID' => $routeId,
                ];
            }

            return ['success' => false, 'message' => 'Credenciales inválidas o error en Service Layer'];
        } catch (\Exception $e) {
            Log::error('SAP Login Error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getBankPages($token, $accountCode = null, $dateFrom = null, $dateTo = null, $accountName = null, $limit = 100, $select = null)
    {
        $filters = [];

        if ($accountCode) {
            $filters[] = "AccountCode eq '{$accountCode}'";
        }

        if ($dateFrom && $dateTo) {
            $filters[] = "DueDate ge '{$dateFrom}' and DueDate le '{$dateTo}'";
        }

        if ($accountName) {
            $filters[] = "substringof('{$accountName}', AccountName)";
        }

        $filterString = count($filters) > 0 ? implode(' and ', $filters) : null;
        
        $params = [
            '$orderby' => 'DueDate desc',
        ];

        if ($select) {
            $params['$select'] = $select;
        }

        // Intentamos ambas formas de conteo para asegurar compatibilidad
        $params['$count'] = 'true'; 
        $params['$inlinecount'] = 'allpages';

        if ($limit) {
            $params['$top'] = $limit;
        }

        if ($filterString) {
            $params['$filter'] = $filterString;
        }

        $url = "{$this->baseUrl}/BankPages";
        
        try {
            $response = Http::withOptions(['verify' => false])
                ->withCookies(['B1SESSION' => $token], parse_url($this->baseUrl, PHP_URL_HOST))
                ->get($url, $params);

            if ($response->successful()) {
                $json = $response->json();
                
                // Buscamos el conteo en todas las posibles ubicaciones de OData
                $count = $json['@odata.count'] ?? $json['odata.count'] ?? $json['count'] ?? count($json['value'] ?? []);

                return [
                    'total_count' => $count,
                    'records' => $json['value'] ?? []
                ];
            }

            $errorData = $response->json();
            $errorMessage = $errorData['error']['message']['value'] ?? 'Error desconocido en SAP';
            return ['error' => $errorMessage];
        } catch (\Exception $e) {
            return ['error' => 'No se pudo conectar con SAP: ' . $e->getMessage()];
        }
    }

    public function deleteBankPage($accountCode, $sequence, $token)
    {
        try {
            $response = Http::withOptions(['verify' => false])
                ->withCookies(['B1SESSION' => $token], parse_url($this->baseUrl, PHP_URL_HOST))
                ->delete("{$this->baseUrl}/BankPages(AccountCode='{$accountCode}',Sequence={$sequence})");

            if (!$response->successful()) {
                \Illuminate\Support\Facades\Log::error("SAP Delete Error para AccountCode {$accountCode} Sequence {$sequence}: " . $response->body());
                return false;
            }

            return true;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("SAP Delete Exception para AccountCode {$accountCode} Sequence {$sequence}: " . $e->getMessage());
            return false;
        }
    }
}
