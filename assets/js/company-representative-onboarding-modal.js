/**
 * Company Representative Onboarding Modal
 * 
 * Displays a modal with process flow information for company representatives
 * every time they access the dashboard after successful authentication.
 */

(function() {
    /**
     * Initialize the onboarding modal
     */
    function initializeModal() {
        const modalElement = document.getElementById('companyRepresentativeOnboardingModal');
        
        if (!modalElement) {
            console.warn('Company representative onboarding modal not found');
            return;
        }
        
        // Show the modal every time
        const modal = new bootstrap.Modal(modalElement, {
            backdrop: true,
            keyboard: true
        });
        
        // Show the modal
        modal.show();
    }
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeModal);
    } else {
        initializeModal();
    }
})();
