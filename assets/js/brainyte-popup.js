/**
 * Brainyte Popup - Dynamic information modal for "Powered by Brainyte" footer.
 * Brainyte and Wells Interactive Services Ltd information.
 * Include *this* script on every page after the footer.
 * ********
 */

(function () {
    'use strict';

    // Brainyte and Wells Interactive information
    const BRAINYTE_INFO = {
        name: 'Brainyte',
        tagline: 'The development arm of Wells Interactive',
        description: 'Brainyte provides intelligen solutions designed for small, medium and large scale businesses. Our platform empowers restaurants with real-time inventory tracking, comprehensive reporting, and seamless multi-device order management.',
        logoUrl: '/assets/images/brainyte-icon.png',
        contact: {
            email: 'brainytellc@gmail.com',
            website: 'http://www.wellsint.site/brainyte',
        },
        parent: {
            name: 'Wells Interactive Services Ltd',
            description: 'Innovating digital solutions for businesses across Africa. Wells Interactive Services Ltd is a technology company specializing in IT Consulting, HR solutions, and business automation.',
            contact: {
                email: 'wellsintltd@gmail.com',
                website: 'https://linktr.ee/wellsinteractive',
                phone: '+234 8067 519 239',
            },
        },
    };

    // Create the popup HTML structure
    function createPopup() {
        // Check if popup already exists
        if (document.getElementById('brainytePopupOverlay')) return;

        const overlay = document.createElement('div');
        overlay.id = 'brainytePopupOverlay';
        overlay.className = 'brainyte-popup-overlay';

        overlay.innerHTML = `
            <div class="brainyte-popup" role="dialog" aria-modal="true" aria-labelledby="brainytePopupTitle">
                <div class="brainyte-popup-header">
                    <div class="brainyte-popup-logo">
                        <span class="brainyte-icon-lg" aria-hidden="true">B</span>
                        <h2 id="brainytePopupTitle">${BRAINYTE_INFO.name}</h2>
                    </div>
                    <button type="button" class="brainyte-popup-close" id="brainytePopupClose" aria-label="Close">
                        ✕
                    </button>
                </div>
                <div class="brainyte-popup-body">
                    <div class="brainyte-popup-section">
                        <h3>About ${BRAINYTE_INFO.name}</h3>
                        <p>${BRAINYTE_INFO.description}</p>
                        <p style="margin-top:8px;"><strong>Email:</strong> <a href="mailto:${BRAINYTE_INFO.contact.email}">${BRAINYTE_INFO.contact.email}</a></p>
                        <p><strong>Website:</strong> <a href="${BRAINYTE_INFO.contact.website}" target="_blank" rel="noopener noreferrer">${BRAINYTE_INFO.contact.website}</a></p>
                    </div>
                    <div class="brainyte-popup-section">
                        <h3>Parent Company</h3>
                        <p><strong>${BRAINYTE_INFO.parent.name}</strong></p>
                        <p>${BRAINYTE_INFO.parent.description}</p>
                        <p style="margin-top:8px;"><strong>Email:</strong> <a href="mailto:${BRAINYTE_INFO.parent.contact.email}">${BRAINYTE_INFO.parent.contact.email}</a></p>
                        <p><strong>Website:</strong> <a href="${BRAINYTE_INFO.parent.contact.website}" target="_blank" rel="noopener noreferrer">${BRAINYTE_INFO.parent.contact.website}</a></p>
                    </div>
                    <div class="brainyte-popup-footer-text">
                        &copy; ${new Date().getFullYear()} ${BRAINYTE_INFO.parent.name}. All rights reserved.
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);

        // Close handlers
        const closeBtn = document.getElementById('brainytePopupClose');
        closeBtn.addEventListener('click', closePopup);
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closePopup();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlay.classList.contains('active')) {
                closePopup();
            }
        });
    }

    function openPopup(e) {
        e.preventDefault();
        createPopup();
        const overlay = document.getElementById('brainytePopupOverlay');
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closePopup() {
        const overlay = document.getElementById('brainytePopupOverlay');
        if (overlay) {
            overlay.classList.remove('active');
            document.body.style.overflow = '';
        }
    }

    // Attach click handlers to all footer links
    function attachFooterHandlers() {
        document.querySelectorAll('.footer-link').forEach(function (link) {
            link.addEventListener('click', openPopup);
        });
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attachFooterHandlers);
    } else {
        attachFooterHandlers();
    }
})();

