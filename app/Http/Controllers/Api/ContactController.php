<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ContactMessageReceived;
use App\Models\ContactMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'company' => ['nullable', 'string', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'message' => ['required', 'string', 'max:4000'],
        ]);

        $row = ContactMessage::query()->create([
            ...$data,
            'source' => 'landing',
            'ip' => $request->ip(),
        ]);

        $this->notifyTeam($row);

        return response()->json([
            'ok' => true,
            'id' => $row->id,
            'message' => 'Demande reçue. Nous vous recontactons rapidement.',
        ], 201);
    }

    /** Demandes reçues, pour l'espace plateforme (super-admin). ?status=open|handled */
    public function index(Request $request): JsonResponse
    {
        $query = ContactMessage::query()->latest()->latest('id')->limit(500);
        $status = $request->query('status');
        if ($status === 'open') {
            $query->whereNull('handled_at');
        } elseif ($status === 'handled') {
            $query->whereNotNull('handled_at');
        }

        return response()->json($query->get()->map->toApi()->values());
    }

    /** Marque une demande comme traitée, ou la rouvre. */
    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['handled' => ['required', 'boolean']]);
        $row = ContactMessage::query()->findOrFail($id);
        $row->update(['handled_at' => $data['handled'] ? now() : null]);

        return response()->json($row->toApi());
    }

    /**
     * Envoie la demande aux adresses de CONTACT_NOTIFY_EMAIL.
     * Un échec d'envoi ne fait jamais échouer le formulaire : la demande est déjà en base.
     */
    private function notifyTeam(ContactMessage $row): void
    {
        $recipients = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('services.contact.notify_email')),
        )));
        if (! $recipients) {
            return;
        }

        try {
            Mail::to($recipients)->send(new ContactMessageReceived($row));
        } catch (\Throwable $e) {
            Log::error('Contact: envoi de la notification impossible', [
                'contact_id' => $row->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
