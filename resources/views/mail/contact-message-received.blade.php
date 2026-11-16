Nouvelle demande reçue depuis le formulaire de contact du site.

Nom : {{ $contact->name }}
E-mail : {{ $contact->email }}
Entreprise : {{ $contact->company ?: '—' }}
Téléphone : {{ $contact->phone ?: '—' }}
Reçu le : {{ $contact->created_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') }}

Message :
{{ $contact->message }}

—
Répondez directement à cet e-mail pour écrire à {{ $contact->name }}.
Toutes les demandes sont aussi visibles dans l'espace plateforme, rubrique « Demandes de contact ».
