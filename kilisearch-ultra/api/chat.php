<?php

require_once __DIR__ . '/../bootstrap.php';

use Kili\Core\FormEngine;

session_start();
header('Content-Type: application/json');

/** Shapes the response for an in-progress (or just-started) form turn. */
function kili_chat_form_turn(FormEngine $formEngine, array $state, ?string $message): array
{
    $field = $formEngine->currentField($state);
    $template = $formEngine->template($state['template_id']);

    return [
        'intent' => 'form',
        'reply' => $message,
        'detected' => [],
        'results' => [],
        'total' => 0,
        'form' => [
            'active' => true,
            'template_id' => $state['template_id'],
            'step' => $state['step'] + 1,
            'total_steps' => count($template['fields'] ?? []),
            'field' => $field,
        ],
    ];
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$message = trim($input['message'] ?? ($_GET['message'] ?? ''));

if ($message === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => ['code' => 'VALIDATION_ERROR', 'message' => '"message" is required'],
    ]);
    exit;
}

$conversation = kili_conversation_engine();
$formEngine = kili_form_engine();

// 1. A form is already in progress — the message is the answer to the
// current field, not a new intent. Session-based, so no database needed.
if (!empty($_SESSION['kili_form'])) {
    $result = $formEngine->submitAnswer($_SESSION['kili_form'], $message);
    $state = $result['state'];

    if ($result['error'] !== null) {
        $_SESSION['kili_form'] = $state; // unchanged — re-ask the same field
        echo json_encode(['success' => true, 'data' => kili_chat_form_turn($formEngine, $state, $result['error'])]);
        exit;
    }

    if ($formEngine->isComplete($state)) {
        $submission = $state['data'];
        $submission['template_id'] = $state['template_id'];
        $submission['status'] = 'new';
        $saved = kili_submissions_storage()->save($submission);
        unset($_SESSION['kili_form']);

        echo json_encode([
            'success' => true,
            'data' => [
                'intent' => 'form_complete',
                'reply' => $formEngine->successMessage($state),
                'detected' => [],
                'results' => [],
                'total' => 0,
                'submission_id' => $saved['id'],
            ],
        ]);
        exit;
    }

    $_SESSION['kili_form'] = $state;
    echo json_encode(['success' => true, 'data' => kili_chat_form_turn($formEngine, $state, null)]);
    exit;
}

$intent = $conversation->detectIntent($message);

// 2. Start a new form.
if ($intent === 'start_enquiry') {
    $state = $formEngine->start('enquiry');
    $_SESSION['kili_form'] = $state;
    $template = $formEngine->template('enquiry');

    echo json_encode(['success' => true, 'data' => kili_chat_form_turn($formEngine, $state, $template['intro'] ?? null)]);
    exit;
}

// 3. Small talk — templated reply, no search.
if (in_array($intent, ['greeting', 'thanks', 'help', 'unknown'], true)) {
    echo json_encode([
        'success' => true,
        'data' => [
            'intent' => $intent,
            'reply' => $conversation->respond($intent),
            'detected' => [],
            'results' => [],
            'total' => 0,
        ],
    ]);
    exit;
}

// 4. FAQ memory — has something like this been asked before? Skips the
// search entirely if so, and can point back to specific listings.
$faqMatch = kili_faq_engine()->match($message);
if ($faqMatch !== null) {
    kili_bump_faq_hit($faqMatch['id']);
    $linked = kili_resolve_sources(array_values(array_filter(array_map(
        fn($id) => kili_storage()->find($id),
        $faqMatch['linked_record_ids'] ?? []
    ))));

    echo json_encode([
        'success' => true,
        'data' => [
            'intent' => 'faq',
            'reply' => $faqMatch['answer'],
            'detected' => [],
            'results' => $linked,
            'total' => count($linked),
            'faq_id' => $faqMatch['id'],
        ],
    ]);
    exit;
}

// 5. Ordinary search — logged so repeated questions become visible and
// can later be promoted into data/faq.json by an admin.
kili_log_query($message);

$context = kili_extract_context($message);
$result = kili_search_engine()->search($message, [], $context, 10, 0);
$results = kili_resolve_sources($result['results']);

$reply = $conversation->respond('find_service', [
    'query' => $message,
    'count' => $result['total'],
    'location' => $context['location'],
]);

echo json_encode([
    'success' => true,
    'data' => [
        'intent' => $intent,
        'reply' => $reply,
        'detected' => array_filter($context, fn($v) => $v !== null),
        'results' => $results,
        'total' => $result['total'],
        'offer_ticket' => $result['total'] === 0,
    ],
]);
