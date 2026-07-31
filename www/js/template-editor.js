/*
 * Template manager behaviour.
 *
 * Two <script> blocks emitted from inside renderTemplatesContent() in
 * www/templates.php, which mixed them with the HTML they acted on. Neither
 * contained a single PHP expression, so nothing is lost by serving them as a
 * file. insertVariable() stays global: the variable buttons call it from
 * onclick attributes, which is the remaining reason the CSP still needs
 * 'unsafe-inline'.
 *
 * Both guard on the elements they need being present, so loading this on a
 * page without the template editor is a no-op.
 */

// Function to insert a variable at cursor position
function insertVariable(variable) {
    const textarea = document.getElementById('template-content');
    if (!textarea) return;
    
    const startPos = textarea.selectionStart;
    const endPos = textarea.selectionEnd;
    const scrollTop = textarea.scrollTop;
    
    // Insert the variable
    textarea.value = textarea.value.substring(0, startPos) + 
                     '{{' + variable + '}}' + 
                     textarea.value.substring(endPos);
    
    // Reset cursor position and focus
    textarea.selectionStart = startPos + variable.length + 4; // +4 for {{ and }}
    textarea.selectionEnd = startPos + variable.length + 4;
    textarea.scrollTop = scrollTop;
    textarea.focus();
}

// Function to insert a conditional block
function insertVariableBlock(variable) {
    const textarea = document.getElementById('template-content');
    if (!textarea) return;
    
    const startPos = textarea.selectionStart;
    const endPos = textarea.selectionEnd;
    const scrollTop = textarea.scrollTop;
    
    // Create the block content
    const blockContent = '{{#' + variable + '}}\n# Your content here\n{{/' + variable + '}}';
    
    // Insert the block
    textarea.value = textarea.value.substring(0, startPos) + 
                     blockContent + 
                     textarea.value.substring(endPos);
    
    // Reset cursor position and focus
    textarea.selectionStart = startPos + blockContent.indexOf('# Your content') + 2;
    textarea.selectionEnd = startPos + blockContent.indexOf('# Your content') + 17;
    textarea.scrollTop = scrollTop;
    textarea.focus();
}

// Setup the reference window
document.addEventListener('DOMContentLoaded', function() {
    const referenceWindow = document.getElementById('variableReferenceWindow');
    const showReferenceBtn = document.getElementById('showReferenceBtn');
    const closeReferenceBtn = document.getElementById('closeReferenceBtn');
    
    // Initial position from localStorage or default values
    let windowPos = {
        left: localStorage.getItem('refWindowLeft') || '20px',
        top: localStorage.getItem('refWindowTop') || '100px'
    };
    
    referenceWindow.style.left = windowPos.left;
    referenceWindow.style.top = windowPos.top;
    
    // Show the reference window
    showReferenceBtn.addEventListener('click', function() {
        referenceWindow.style.display = 'block';
    });
    
    // Close the reference window
    closeReferenceBtn.addEventListener('click', function() {
        referenceWindow.style.display = 'none';
    });
    
    // Make the window draggable
    let isDragging = false;
    let dragOffset = { x: 0, y: 0 };
    
    const header = referenceWindow.querySelector('.reference-window-header');
    
    header.addEventListener('mousedown', function(e) {
        isDragging = true;
        dragOffset.x = e.clientX - referenceWindow.getBoundingClientRect().left;
        dragOffset.y = e.clientY - referenceWindow.getBoundingClientRect().top;
        
        // Prevent text selection during drag
        e.preventDefault();
    });
    
    document.addEventListener('mousemove', function(e) {
        if (!isDragging) return;
        
        const newLeft = e.clientX - dragOffset.x;
        const newTop = e.clientY - dragOffset.y;
        
        // Keep the window within the viewport
        const maxLeft = window.innerWidth - referenceWindow.offsetWidth;
        const maxTop = window.innerHeight - referenceWindow.offsetHeight;
        
        referenceWindow.style.left = Math.max(0, Math.min(newLeft, maxLeft)) + 'px';
        referenceWindow.style.top = Math.max(0, Math.min(newTop, maxTop)) + 'px';
    });
    
    document.addEventListener('mouseup', function() {
        if (isDragging) {
            isDragging = false;
            
            // Save position in localStorage
            localStorage.setItem('refWindowLeft', referenceWindow.style.left);
            localStorage.setItem('refWindowTop', referenceWindow.style.top);
        }
    });
});

document.addEventListener('DOMContentLoaded', function() {
    // Toggle the existing template selector based on the checkbox
    const useExistingCheck = document.getElementById('use-existing');
    const existingTemplateSelect = document.getElementById('existing-template-select');
    
    if (useExistingCheck && existingTemplateSelect) {
        useExistingCheck.addEventListener('change', function() {
            existingTemplateSelect.style.display = this.checked ? 'block' : 'none';
        });
    }
});
