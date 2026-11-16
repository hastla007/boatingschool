{{--
    Verwalterauswahl einer Lerngruppe: das Teilnehmer-Suchfeld
    (admin/_participant-picker) über admin.learning-groups.manager-candidates
    (höchstens LearningGroupController::MANAGER_SEARCH_LIMIT aktive
    Teilnehmer). Ohne JavaScript löst der Server die eingetippte
    E-Mail-Adresse auf (LearningGroupController::resolveOwnerId()).

    $selected: bisheriger Verwalter (Detailseite) oder null.
--}}
@include('admin._participant-picker', [
    'id' => 'owner_email',
    'name' => 'owner_email',
    'idName' => 'owner_user_id',
    'label' => 'Verwalter (aktiver Teilnehmer)',
    'url' => route('admin.learning-groups.manager-candidates'),
    'limit' => \App\Http\Controllers\Admin\LearningGroupController::MANAGER_SEARCH_LIMIT,
    'selected' => $selected,
    'messages' => array_merge($errors->get('owner_user_id'), $errors->get('owner_email')),
    'hint' => 'E-Mail-Adresse des Teilnehmers eingeben oder nach dem Namen suchen und einen Treffer wählen.',
    'emptyMessage' => 'Keine aktiven Teilnehmer gefunden.',
])
