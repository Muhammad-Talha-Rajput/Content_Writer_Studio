<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\BrandProfile;
use App\Models\Client;
use App\Support\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BrandProfileController extends Controller
{
    public function update(Request $request, Client $client): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:0'],
            'default_audience' => ['nullable', 'string', 'max:500'], 'default_tone' => ['nullable', 'string', 'max:300'],
            'preferred_terms' => ['present', 'array', 'max:50'], 'preferred_terms.*' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'prohibited_terms' => ['present', 'array', 'max:50'], 'prohibited_terms.*' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'claims_requiring_evidence' => ['nullable', 'string', 'max:5000'],
            'approved_facts' => ['nullable', 'string', 'max:10000'], 'sample_copy' => ['nullable', 'string', 'max:10000'],
        ]);
        $profile = DB::transaction(function () use ($request, $client, $data) {
            $client = Client::query()->lockForUpdate()->findOrFail($client->id);
            WorkspaceAccess::editable($request->user(), $client);
            $profile = BrandProfile::where('client_id', $client->id)->first();
            abort_if(($profile?->version ?? 0) !== (int) $data['version'], 409, 'This brand profile changed. Reload the saved version before editing.');
            $profile ??= new BrandProfile(['client_id' => $client->id]);
            $profile->fill(['default_audience' => $data['default_audience'] ?? null, 'default_tone' => $data['default_tone'] ?? null,
                'guidelines' => collect($data)->except(['version', 'default_audience', 'default_tone'])->all(),
                'updated_by' => $request->user()->id, 'version' => ($profile->version ?? 0) + 1])->save();
            AuditEvent::record($request->user(), 'brand_profile.saved', 'CLIENT', $client->id);

            return $profile;
        });

        return response()->json(['brand_profile' => $profile]);
    }
}
