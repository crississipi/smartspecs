<?php
/**
 * Render recommendation data into HTML
 */
function renderRecommendation($data) {
    if (!isset($data['type']) || $data['type'] !== 'recommendation') {
        return '<div class="ai-message">' . htmlspecialchars($data['ai_message'] ?? '') . '</div>';
    }
    
    $html = '<div class="smart-recommendation">';
    
    // AI Message Section
    $html .= '<div class="ai-response-section">';
    $html .= '<div class="ai-message">' . htmlspecialchars($data['ai_message'] ?? '') . '</div>';
    $html .= '</div>';
    
    // Budget Warning
    if (isset($data['budget_analysis']) && !($data['budget_analysis']['is_feasible'] ?? true)) {
        $userBudget = $data['budget_analysis']['user_budget'] ?? 0;
        $minRequired = $data['budget_analysis']['min_required'] ?? 0;
        
        $html .= '<div class="budget-warning">';
        $html .= '<div class="warning-header">⚠️ Budget Constraint</div>';
        $html .= '<div class="budget-comparison">';
        $html .= '<div class="budget-item"><span>Your Budget:</span><span class="user-budget">₱' . number_format($userBudget, 0) . '</span></div>';
        $html .= '<div class="budget-item"><span>Minimum Required:</span><span class="min-budget">₱' . number_format($minRequired, 0) . '</span></div>';
        $html .= '</div>';
        $html .= '</div>';
    }
    
    // Components Table
    if (isset($data['components']) && count($data['components']) > 0) {
        $html .= '<div class="components-table-section">';
        $html .= '<h4>Recommended Components</h4>';
        $html .= '<div class="table-container">';
        $html .= '<table class="components-table">';
        $html .= '<thead><tr><th>Type</th><th>Brand</th><th>Model</th><th>Price</th><th>Image</th></tr></thead>';
        $html .= '<tbody>';
        
        foreach ($data['components'] as $comp) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars(strtoupper($comp['type'] ?? '')) . '</td>';
            $html .= '<td>' . htmlspecialchars($comp['brand'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($comp['model'] ?? '') . '</td>';
            $html .= '<td>₱' . number_format($comp['price'] ?? 0, 2) . '</td>';
            $html .= '<td><img src="' . htmlspecialchars($comp['image_url'] ?? '') . '" alt="' . htmlspecialchars($comp['model'] ?? '') . '" class="component-image"></td>';
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table>';
        $html .= '</div></div>';
    }
    
    $html .= '</div>';
    return $html;
}
?>
