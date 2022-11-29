/**
 * Cookie bar logic
 */

define([
    'Amasty_GdprFrontendUi/js/modal-component',
    'jquery',
    'mage/translate',
    'Amasty_GdprFrontendUi/js/model/cookie-data-provider'
], function (
    ModalComponent,
    $,
    $t,
    cookieDataProvider,
) {
    'use strict';

    return ModalComponent.extend({
        defaults: {
            template: 'Amasty_GdprFrontendUi/components/elems',
            allowLink: '/',
            firstShowProcess: '0',
            cookiesName: [],
            domainName: '',
            setupModalTitle: $t('Please select and accept your Cookies Group'),
            isPopup: false,
            isDeclineEnabled: false,
            barLocation: null,
            selectors: {
                barSelector: '[data-amcookie-js="bar"]',
                closeCookieBarButton: '[data-amcookie-js="close-cookiebar"]'
            }
        },

        initialize: function () {
            this._super();

            this.initEventHandlers();
            this.initModalWithData();

            return this;
        },

        initEventHandlers: function () {
            $(this.selectors.closeCookieBarButton).on('click', this.closeCookieBar.bind(this))
        },

        initButtonsEvents: function (buttons) {
            buttons.forEach(function (button) {
                if (button.dataJs !== 'settings') {
                    var elem = $('[data-amgdprcookie-js="' + button.dataJs + '"]');
                    elem.on('click', this.actionSave.bind(this, button, elem));
                    elem.attr('disabled', false);
                } else {
                    $('[data-amgdprcookie-js="' + button.dataJs + '"]')
                        .attr('disabled', false)
                        .on('click', this.getSettingsModal.bind(this));
                }
            }.bind(this));
        },

        getSettingsModal: function (event) {
            event.preventDefault();
            cookieDataProvider.getCookieData().done(function (cookieData) {
                if (this.setupModal) {
                    this.setupModal.items(cookieData.groupData);
                }

                this.openModal();
            }.bind(this));
        },

        /**
         * Open Setup Cookie Modal
         */
        openModal: function () {
            if (!this.setupModal) {
                this.getModalData();

                return;
            }

            this.setupModal.openModal();
        },

        /**
         * Get Setup Modal Data
         */
        getModalData: function () {
            cookieDataProvider.getCookieData().done(function (cookieData) {
                this.initSetupModal(cookieData.groupData);
            }.bind(this));
        },

        /**
         * On allow all cookies callback
         */
        allowCookies: function () {
            this._super().done(function () {
                this.closeCookieBar();
                location.reload();
            }.bind(this));
        },

        _performSave: function () {
            this._super();

            this.closeCookieBar();
            location.reload();
        },

        closeCookieBar: function () {
            $(this.selectors.barSelector).remove();
        }
    });
});
