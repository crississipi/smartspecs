<?php
header('Content-Type: application/json');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Only POST allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
    exit;
}

$component = $input['component'] ?? '';
$index = $input['index'] ?? -1;
$imageUrl = $input['image_url'] ?? '';
$link = $input['link'] ?? '';

// Validate component name (prevent path traversal)
$allowedComponents = ['case', 'cooler', 'cpu', 'gpu', 'motherboard', 'psu', 'ram', 'storage'];
if (!in_array($component, $allowedComponents)) {
    echo json_encode(['success' => false, 'error' => 'Invalid component: ' . $component]);
    exit;
}

if ($index < 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid index']);
    exit;
}

$filePath = __DIR__ . '/../data/components/' . $component . '.json';

if (!file_exists($filePath)) {
    echo json_encode(['success' => false, 'error' => 'Component file not found']);
    exit;
}

// Read the JSON file
$jsonContent = file_get_contents($filePath);

// Strip UTF-8 BOM if present
$bom = pack('H*', 'EFBBBF');
$jsonContent = preg_replace("/^$bom/", '', $jsonContent);

$data = json_decode($jsonContent, true);

if ($data === null) {
    echo json_encode(['success' => false, 'error' => 'Failed to parse JSON']);
    exit;
}

if ($index >= count($data)) {
    echo json_encode(['success' => false, 'error' => 'Index out of range']);
    exit;
}

// Update the fields
$data[$index]['image_url'] = $imageUrl;
$data[$index]['link'] = $link;

// Write back with pretty print
$newJson = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if (file_put_contents($filePath, $newJson) === false) {
    echo json_encode(['success' => false, 'error' => 'Failed to write file']);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Saved ' . $data[$index]['name'] . ' in ' . $component . '.json',
    'updated' => [
        'name' => $data[$index]['name'],
        'image_url' => $imageUrl,
        'link' => $link
    ]
]);
