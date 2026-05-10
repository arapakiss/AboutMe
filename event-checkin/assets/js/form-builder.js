/**
 * Event Check-in - Form Builder Admin JS
 * Drag-and-drop form construction with SortableJS.
 */
(function($) {
    'use strict';

    var FB = {
        schema: null,
        currentStepIndex: 0,
        selectedFieldId: null,
        eventId: 0,
        sortableInstances: [],

        init: function() {
            this.renderPalette();
            this.bindEvents();

            // Load schema if event is pre-selected.
            var sel = $('#ec-event-selector').val();
            if (sel) {
                this.eventId = parseInt(sel, 10);
                this.loadSchema(this.eventId);
            }
        },

        // ── Palette ──
        renderPalette: function() {
            var $palette = $('#ec-palette-fields');
            $palette.empty();
            var cats = ecFormBuilder.categories;
            var types = ecFormBuilder.fieldTypes;

            Object.keys(cats).forEach(function(catKey) {
                var $cat = $('<div class="ec-palette-category">');
                $cat.append('<div class="ec-palette-category-title">' + cats[catKey] + '</div>');
                var $items = $('<div class="ec-palette-category-items">');

                Object.keys(types).forEach(function(typeKey) {
                    var t = types[typeKey];
                    if (t.category !== catKey) return;
                    var $field = $('<div class="ec-palette-field" data-type="' + typeKey + '">')
                        .append('<span class="ec-palette-field-icon">' + t.icon + '</span>')
                        .append('<span>' + t.label + '</span>');
                    $items.append($field);
                });

                $cat.append($items);
                $palette.append($cat);

                // Initialize Sortable on each category's items container
                // so draggable fields are direct children of the sortable element.
                new Sortable($items[0], {
                    group: { name: 'fields', pull: 'clone', put: false },
                    sort: false,
                    draggable: '.ec-palette-field',
                    ghostClass: 'sortable-ghost',
                    forceFallback: true,
                    fallbackClass: 'sortable-fallback',
                    fallbackOnBody: true,
                    onEnd: function() {}
                });
            });
        },

        // ── Events ──
        bindEvents: function() {
            var self = this;

            $('#ec-event-selector').on('change', function() {
                var id = parseInt($(this).val(), 10);
                if (id) {
                    self.eventId = id;
                    self.loadSchema(id);
                    // Update URL without reload.
                    var url = new URL(window.location);
                    url.searchParams.set('event_id', id);
                    history.replaceState(null, '', url);
                }
            });

            $('#ec-save-form').on('click', function() { self.saveSchema(); });
            $('#ec-add-step').on('click', function() { self.addStep(); });
            $('#ec-settings-close').on('click', function() { self.deselectField(); });

            $('#ec-create-page').on('click', function() {
                if (!self.eventId) { alert('Select an event first.'); return; }
                self.createFormPage();
            });

            $('#ec-create-kiosk').on('click', function() {
                if (!self.eventId) { alert('Select an event first.'); return; }
                self.createKioskPage();
            });

            // Import / Export.
            $('#ec-export-form').on('click', function() { self.exportSchema(); });
            $('#ec-import-form').on('click', function() { $('#ec-import-file').click(); });
            $('#ec-import-file').on('change', function(e) { self.importSchema(e); });

            // DeepL Translation.
            $('#ec-deepl-test').on('click', function() { self.testDeepLKey(); });
            $('#ec-deepl-translate').on('click', function() { self.generateTranslations(); });
        },

        // ── Load / Save ──
        loadSchema: function(eventId) {
            var self = this;
            $.post(ecFormBuilder.ajaxUrl, {
                action: 'ec_load_form_schema',
                nonce: ecFormBuilder.nonce,
                event_id: eventId
            }, function(res) {
                if (res.success) {
                    self.schema = res.data.schema;
                    self.currentStepIndex = 0;
                    self.selectedFieldId = null;
                    self.renderAll();
                    $('#ec-canvas-empty').hide();
                }
            });
        },

        saveSchema: function() {
            var self = this;
            if (!this.eventId || !this.schema) return;

            var $btn = $('#ec-save-form');
            $btn.text(ecFormBuilder.i18n.saving).prop('disabled', true);

            $.post(ecFormBuilder.ajaxUrl, {
                action: 'ec_save_form_schema',
                nonce: ecFormBuilder.nonce,
                event_id: this.eventId,
                schema: JSON.stringify(this.schema)
            }, function(res) {
                $btn.text('Save Form').prop('disabled', false);
                if (res.success) {
                    self.showToast(ecFormBuilder.i18n.saved);
                } else {
                    self.showToast(res.data.message || 'Error', true);
                }
            });
        },

        getPageOptions: function() {
            return {
                hide_header: $('#ec-opt-hide-header').is(':checked') ? 1 : 0,
                hide_footer: $('#ec-opt-hide-footer').is(':checked') ? 1 : 0
            };
        },

        createFormPage: function() {
            var self = this;
            var opts = this.getPageOptions();
            $.post(ecFormBuilder.ajaxUrl, {
                action: 'ec_create_form_page',
                nonce: ecFormBuilder.nonce,
                event_id: this.eventId,
                hide_header: opts.hide_header,
                hide_footer: opts.hide_footer
            }, function(res) {
                if (res.success) {
                    self.showToast(ecFormBuilder.i18n.pageCreated);
                    if (res.data.page_url) {
                        window.open(res.data.page_url, '_blank');
                    }
                }
            });
        },

        createKioskPage: function() {
            var self = this;
            var opts = this.getPageOptions();
            $.post(ecFormBuilder.ajaxUrl, {
                action: 'ec_create_kiosk_page',
                nonce: ecFormBuilder.nonce,
                event_id: this.eventId,
                hide_header: opts.hide_header,
                hide_footer: opts.hide_footer
            }, function(res) {
                if (res.success) {
                    self.showToast(res.data.message || 'Kiosk page created!');
                    if (res.data.page_url) {
                        window.open(res.data.page_url, '_blank');
                    }
                } else {
                    self.showToast(res.data.message || 'Error creating kiosk page', true);
                }
            });
        },

        // ── Render All ──
        renderAll: function() {
            this.renderStepList();
            this.renderCanvas();
        },

        // ── Step List ──
        renderStepList: function() {
            var self = this;
            var $list = $('#ec-step-list');
            $list.empty();

            if (!this.schema || !this.schema.steps) return;

            this.schema.steps.forEach(function(step, i) {
                var $item = $('<div class="ec-step-item' + (i === self.currentStepIndex ? ' active' : '') + '" data-index="' + i + '">');
                $item.append('<span class="ec-step-item-num">' + (i + 1) + '</span>');
                $item.append('<span>' + (step.title || 'Step ' + (i + 1)) + '</span>');

                var $actions = $('<span class="ec-step-item-actions">');
                if (self.schema.steps.length > 1) {
                    $actions.append('<button class="ec-step-delete" data-index="' + i + '" title="Delete">&times;</button>');
                }
                $item.append($actions);
                $list.append($item);
            });

            $list.find('.ec-step-item').on('click', function(e) {
                if ($(e.target).hasClass('ec-step-delete')) return;
                self.currentStepIndex = parseInt($(this).data('index'), 10);
                self.renderAll();
            });

            $list.find('.ec-step-delete').on('click', function(e) {
                e.stopPropagation();
                if (!confirm(ecFormBuilder.i18n.confirmDelete)) return;
                var idx = parseInt($(this).data('index'), 10);
                self.schema.steps.splice(idx, 1);
                if (self.currentStepIndex >= self.schema.steps.length) {
                    self.currentStepIndex = Math.max(0, self.schema.steps.length - 1);
                }
                self.renderAll();
            });
        },

        addStep: function() {
            if (!this.schema) return;
            var stepNum = this.schema.steps.length + 1;
            this.schema.steps.push({
                id: 'step_' + Date.now(),
                title: ecFormBuilder.i18n.stepTitle + ' ' + stepNum,
                subtitle: '',
                kicker: '',
                fields: []
            });
            this.currentStepIndex = this.schema.steps.length - 1;
            this.renderAll();
        },

        // ── Canvas ──
        renderCanvas: function() {
            var self = this;
            var $canvas = $('#ec-canvas-steps');
            $canvas.empty();

            // Destroy old sortable instances.
            this.sortableInstances.forEach(function(s) { s.destroy(); });
            this.sortableInstances = [];

            if (!this.schema || !this.schema.steps) return;

            var step = this.schema.steps[this.currentStepIndex];
            if (!step) return;

            // Step Header.
            var $stepDiv = $('<div class="ec-canvas-step">');
            var $header = $('<div class="ec-canvas-step-header">');
            $header.append('<span style="font-size:10px;font-weight:800;letter-spacing:.14em;text-transform:uppercase;opacity:.6">STEP ' + (this.currentStepIndex + 1) + '</span>');
            var $titleInput = $('<input type="text" value="' + (step.title || '') + '" placeholder="Step title...">');
            $titleInput.on('input', function() {
                step.title = $(this).val();
                self.renderStepList();
            });
            $header.append($titleInput);
            $stepDiv.append($header);

            // Fields area.
            var $fields = $('<div class="ec-canvas-fields" id="ec-drop-zone">');

            if (step.fields.length === 0) {
                $fields.append('<div class="ec-canvas-field-placeholder">' + ecFormBuilder.i18n.noFields + '</div>');
            } else {
                step.fields.forEach(function(field) {
                    $fields.append(self.renderFieldCard(field));
                });
            }

            $stepDiv.append($fields);
            $canvas.append($stepDiv);

            // Make drop zone sortable.
            var sortable = new Sortable($fields[0], {
                group: { name: 'fields', pull: true, put: true },
                animation: 200,
                ghostClass: 'sortable-ghost',
                forceFallback: true,
                fallbackClass: 'sortable-fallback',
                fallbackOnBody: true,
                draggable: '.ec-canvas-field',
                onAdd: function(evt) {
                    // Field added from palette.
                    var type = evt.item.getAttribute('data-type');
                    if (type && ecFormBuilder.fieldTypes[type]) {
                        var newField = Object.assign({
                            id: 'field_' + Date.now() + '_' + Math.random().toString(36).substr(2, 6),
                            type: type
                        }, JSON.parse(JSON.stringify(ecFormBuilder.fieldTypes[type].defaults)));

                        // Insert at the correct position.
                        step.fields.splice(evt.newIndex, 0, newField);
                        self.renderCanvas();
                        self.selectField(newField.id);
                    }
                },
                onSort: function(evt) {
                    // Reorder fields in schema.
                    if (evt.from === evt.to) {
                        var movedField = step.fields.splice(evt.oldIndex, 1)[0];
                        step.fields.splice(evt.newIndex, 0, movedField);
                    }
                }
            });

            this.sortableInstances.push(sortable);
        },

        renderFieldCard: function(field) {
            var self = this;
            var types = ecFormBuilder.fieldTypes;
            var typeDef = types[field.type] || {};

            var $card = $('<div class="ec-canvas-field' + (this.selectedFieldId === field.id ? ' selected' : '') + '" data-field-id="' + field.id + '" data-width="' + (field.width || 'full') + '">');

            // Header row.
            var $header = $('<div class="ec-canvas-field-header">');
            $header.append('<span class="ec-canvas-field-type">' + (typeDef.label || field.type) + '</span>');
            if (field.required) {
                $header.append('<span class="ec-canvas-field-required">Required</span>');
            }
            $card.append($header);

            // Actions.
            var $actions = $('<div class="ec-canvas-field-actions">');
            $actions.append('<button class="ec-field-delete" data-field-id="' + field.id + '" title="Delete">&times;</button>');
            $card.append($actions);

            // Label.
            $card.append('<div class="ec-canvas-field-label">' + (field.label || 'Untitled') + '</div>');

            // Preview.
            var preview = self.getFieldPreview(field);
            if (preview) {
                $card.append('<div class="ec-canvas-field-preview">' + preview + '</div>');
            }

            // Click to select.
            $card.on('click', function(e) {
                if ($(e.target).hasClass('ec-field-delete')) return;
                self.selectField(field.id);
            });

            // Delete.
            $card.find('.ec-field-delete').on('click', function(e) {
                e.stopPropagation();
                if (!confirm(ecFormBuilder.i18n.confirmDelete)) return;
                var step = self.schema.steps[self.currentStepIndex];
                step.fields = step.fields.filter(function(f) { return f.id !== field.id; });
                if (self.selectedFieldId === field.id) self.deselectField();
                self.renderCanvas();
            });

            return $card;
        },

        getFieldPreview: function(field) {
            switch (field.type) {
                case 'short_text':
                case 'long_text':
                case 'email':
                case 'website':
                    return field.placeholder ? '<em style="opacity:.5">' + field.placeholder + '</em>' : '';
                case 'phone':
                    return (field.default_code || '+30') + ' ' + (field.placeholder || '...');
                case 'radio':
                case 'dropdown':
                    return (field.options || []).map(function(o) { return o.label; }).join(' / ');
                case 'checkbox':
                    return (field.options || []).map(function(o) { return o.label; }).join(', ');
                case 'datetime':
                    return field.mode === 'date' ? 'Date picker' : field.mode === 'time' ? 'Time picker' : 'Date & Time';
                case 'file_upload':
                    return 'Accept: ' + (field.accept || '*');
                case 'range':
                    return field.min + ' - ' + field.max + (field.unit ? ' ' + field.unit : '');
                case 'signature':
                    return 'Canvas signature pad';
                case 'social':
                    return (field.platforms || []).join(', ');
                case 'company_info':
                    return 'Company info card';
                case 'country':
                    return 'Country selector';
                default:
                    return '';
            }
        },

        // ── Field Settings ──
        selectField: function(fieldId) {
            this.selectedFieldId = fieldId;
            var field = this.findField(fieldId);
            if (!field) return;

            // Highlight on canvas.
            $('.ec-canvas-field').removeClass('selected');
            $('.ec-canvas-field[data-field-id="' + fieldId + '"]').addClass('selected');

            this.renderSettings(field);
        },

        deselectField: function() {
            this.selectedFieldId = null;
            $('.ec-canvas-field').removeClass('selected');
            $('#ec-settings-body').html('<p class="ec-settings-empty">' + 'Click a field to edit its settings.' + '</p>');
        },

        findField: function(fieldId) {
            if (!this.schema) return null;
            for (var s = 0; s < this.schema.steps.length; s++) {
                for (var f = 0; f < this.schema.steps[s].fields.length; f++) {
                    if (this.schema.steps[s].fields[f].id === fieldId) {
                        return this.schema.steps[s].fields[f];
                    }
                }
            }
            return null;
        },

        renderSettings: function(field) {
            var self = this;
            var $body = $('#ec-settings-body');
            $body.empty();

            var typeDef = ecFormBuilder.fieldTypes[field.type] || {};
            $('#ec-settings-title').text(typeDef.label || 'Field Settings');

            // Common settings.
            if (field.type !== 'hidden' && field.type !== 'section_break') {
                // Label.
                $body.append(self.settingInput('label', ecFormBuilder.i18n.label, field.label || '', field));

                // Placeholder (for text-like fields).
                if (['short_text', 'long_text', 'email', 'phone', 'website'].indexOf(field.type) !== -1) {
                    $body.append(self.settingInput('placeholder', ecFormBuilder.i18n.placeholder, field.placeholder || '', field));
                }

                // Required.
                $body.append(self.settingCheckbox('required', ecFormBuilder.i18n.required, field.required, field));

                // Width.
                $body.append(self.settingWidth(field));
            }

            // Type-specific settings.
            switch (field.type) {
                case 'short_text':
                    $body.append(self.settingInput('maxlength', 'Max Length', field.maxlength || 255, field, 'number'));
                    $body.append(self.settingInput('pattern', 'Regex Pattern', field.pattern || '', field));
                    break;

                case 'long_text':
                    $body.append(self.settingInput('rows', 'Rows', field.rows || 4, field, 'number'));
                    $body.append(self.settingInput('maxlength', 'Max Length', field.maxlength || 5000, field, 'number'));
                    break;

                case 'email':
                    $body.append(self.settingCheckbox('verify', ecFormBuilder.i18n.verification, field.verify, field));
                    break;

                case 'phone':
                    $body.append(self.settingCheckbox('verify', ecFormBuilder.i18n.verification + ' (SMS)', field.verify, field));
                    $body.append(self.settingInput('default_code', 'Default Country Code', field.default_code || '+30', field));
                    break;

                case 'website':
                    $body.append(self.settingCheckbox('show_preview', 'Show Preview Card', field.show_preview, field));
                    break;

                case 'radio':
                case 'checkbox':
                    $body.append(self.settingOptions(field));
                    if (field.type === 'radio' || field.type === 'checkbox') {
                        var layoutOptions = field.type === 'radio' ? ['cards', 'list'] : ['chips', 'cards'];
                        $body.append(self.settingSelect('layout', 'Layout', field.layout, layoutOptions, field));
                    }
                    break;

                case 'dropdown':
                    $body.append(self.settingOptions(field));
                    $body.append(self.settingCheckbox('searchable', 'Searchable', field.searchable, field));
                    break;

                case 'datetime':
                    $body.append(self.settingSelect('mode', 'Mode', field.mode, ['date', 'time', 'both'], field));
                    break;

                case 'file_upload':
                    $body.append(self.settingInput('accept', 'Accepted Types', field.accept || '', field));
                    $body.append(self.settingInput('max_size_mb', 'Max Size (MB)', field.max_size_mb || 10, field, 'number'));
                    $body.append(self.settingCheckbox('multiple', 'Allow Multiple', field.multiple, field));
                    break;

                case 'range':
                    $body.append(self.settingInput('min', 'Min', field.min || 1, field, 'number'));
                    $body.append(self.settingInput('max', 'Max', field.max || 10, field, 'number'));
                    $body.append(self.settingInput('step', 'Step', field.step || 1, field, 'number'));
                    $body.append(self.settingInput('default_val', 'Default', field.default_val || 5, field, 'number'));
                    $body.append(self.settingInput('unit', 'Unit Label', field.unit || '', field));
                    $body.append(self.settingInput('description', 'Description', field.description || '', field));
                    break;

                case 'social':
                    $body.append(self.settingSocialPlatforms(field));
                    break;

                case 'country':
                    $body.append(self.settingCheckbox('searchable', 'Searchable', field.searchable, field));
                    break;

                case 'company_info':
                    $body.append(self.settingCheckbox('show_logo', 'Show Logo', field.show_logo, field));
                    $body.append(self.settingCheckbox('show_desc', 'Show Description', field.show_desc, field));
                    $body.append(self.settingCheckbox('show_social', 'Show Social Links', field.show_social, field));
                    break;

                case 'hidden':
                    $body.append(self.settingInput('name', 'Field Name', field.name || '', field));
                    $body.append(self.settingInput('value', 'Value', field.value || '', field));
                    break;

                case 'section_break':
                    $body.append(self.settingInput('title', 'Title', field.title || '', field));
                    $body.append(self.settingInput('description', 'Description', field.description || '', field));
                    break;
            }

            // Delete button.
            var $del = $('<div class="ec-setting-group" style="margin-top:24px;padding-top:16px;border-top:2px solid rgba(0,45,114,.1)">');
            var $delBtn = $('<button class="ec-builder-btn ec-builder-btn--danger ec-btn-full">Delete Field</button>');
            $delBtn.on('click', function() {
                if (!confirm(ecFormBuilder.i18n.confirmDelete)) return;
                var step = self.schema.steps[self.currentStepIndex];
                step.fields = step.fields.filter(function(f) { return f.id !== field.id; });
                self.deselectField();
                self.renderCanvas();
            });
            $del.append($delBtn);
            $body.append($del);
        },

        // ── Setting Helpers ──
        settingInput: function(key, label, value, field, type) {
            var self = this;
            var $group = $('<div class="ec-setting-group">');
            $group.append('<label class="ec-setting-label">' + label + '</label>');
            var $input = $('<input class="ec-setting-input" type="' + (type || 'text') + '" value="' + (value || '') + '">');
            $input.on('input', function() {
                var val = $(this).val();
                if (type === 'number') val = parseFloat(val) || 0;
                field[key] = val;
                self.renderCanvas();
            });
            $group.append($input);
            return $group;
        },

        settingCheckbox: function(key, label, checked, field) {
            var self = this;
            var $group = $('<div class="ec-setting-group">');
            var $label = $('<label class="ec-setting-checkbox">');
            var $input = $('<input type="checkbox"' + (checked ? ' checked' : '') + '>');
            $input.on('change', function() {
                field[key] = $(this).is(':checked');
                self.renderCanvas();
            });
            $label.append($input).append(label);
            $group.append($label);
            return $group;
        },

        settingSelect: function(key, label, value, options, field) {
            var self = this;
            var $group = $('<div class="ec-setting-group">');
            $group.append('<label class="ec-setting-label">' + label + '</label>');
            var $select = $('<select class="ec-setting-select">');
            options.forEach(function(opt) {
                $select.append('<option value="' + opt + '"' + (opt === value ? ' selected' : '') + '>' + opt.charAt(0).toUpperCase() + opt.slice(1) + '</option>');
            });
            $select.on('change', function() {
                field[key] = $(this).val();
                self.renderCanvas();
            });
            $group.append($select);
            return $group;
        },

        settingWidth: function(field) {
            var self = this;
            var $group = $('<div class="ec-setting-group">');
            $group.append('<label class="ec-setting-label">' + ecFormBuilder.i18n.width + '</label>');
            var $selector = $('<div class="ec-width-selector">');

            Object.keys(ecFormBuilder.widthOptions).forEach(function(key) {
                var opt = ecFormBuilder.widthOptions[key];
                var $btn = $('<button type="button" class="ec-width-option' + (field.width === key ? ' active' : '') + '" data-width="' + key + '">' + opt.label + '</button>');
                $btn.on('click', function() {
                    field.width = key;
                    $selector.find('.ec-width-option').removeClass('active');
                    $(this).addClass('active');
                    self.renderCanvas();
                });
                $selector.append($btn);
            });

            $group.append($selector);
            return $group;
        },

        settingOptions: function(field) {
            var self = this;
            var $group = $('<div class="ec-setting-group">');
            $group.append('<label class="ec-setting-label">' + ecFormBuilder.i18n.options + '</label>');
            var $editor = $('<div class="ec-options-editor">');

            (field.options || []).forEach(function(opt, i) {
                var $row = $('<div class="ec-option-row">');
                var $labelInput = $('<input type="text" value="' + (opt.label || '') + '" placeholder="Label">');
                $labelInput.on('input', function() {
                    field.options[i].label = $(this).val();
                    if (!field.options[i].value || field.options[i].value === self.slugify(opt.label)) {
                        field.options[i].value = self.slugify($(this).val());
                    }
                    self.renderCanvas();
                });
                $row.append($labelInput);

                var $delBtn = $('<button type="button">&times;</button>');
                $delBtn.on('click', function() {
                    field.options.splice(i, 1);
                    self.renderSettings(field);
                    self.renderCanvas();
                });
                $row.append($delBtn);
                $editor.append($row);
            });

            var $addBtn = $('<button type="button" class="ec-builder-btn ec-builder-btn--ghost ec-btn-full" style="margin-top:8px">+ ' + ecFormBuilder.i18n.addOption + '</button>');
            $addBtn.on('click', function() {
                if (!field.options) field.options = [];
                var num = field.options.length + 1;
                field.options.push({ label: 'Option ' + num, value: 'option_' + num, description: '' });
                self.renderSettings(field);
                self.renderCanvas();
            });

            $group.append($editor);
            $group.append($addBtn);
            return $group;
        },

        settingSocialPlatforms: function(field) {
            var self = this;
            var allPlatforms = ['linkedin', 'twitter', 'instagram', 'facebook', 'youtube', 'tiktok', 'github'];
            var $group = $('<div class="ec-setting-group">');
            $group.append('<label class="ec-setting-label">Platforms</label>');

            allPlatforms.forEach(function(platform) {
                var checked = (field.platforms || []).indexOf(platform) !== -1;
                var $label = $('<label class="ec-setting-checkbox" style="margin-bottom:4px">');
                var $input = $('<input type="checkbox"' + (checked ? ' checked' : '') + '>');
                $input.on('change', function() {
                    if (!field.platforms) field.platforms = [];
                    if ($(this).is(':checked')) {
                        field.platforms.push(platform);
                    } else {
                        field.platforms = field.platforms.filter(function(p) { return p !== platform; });
                    }
                    self.renderCanvas();
                });
                $label.append($input).append(platform.charAt(0).toUpperCase() + platform.slice(1));
                $group.append($label);
            });

            return $group;
        },

        // ── DeepL Translation ──
        testDeepLKey: function() {
            var apiKey = $('#ec-deepl-api-key').val().trim();
            if (!apiKey) { alert('Enter a DeepL API key first.'); return; }

            var $btn = $('#ec-deepl-test');
            $btn.text('Testing...').prop('disabled', true);

            $.post(ecFormBuilder.ajaxUrl, {
                action: 'ec_deepl_test_key',
                nonce: ecFormBuilder.nonce,
                api_key: apiKey
            }, function(res) {
                $btn.text('Test Key').prop('disabled', false);
                if (res.success) {
                    $('#ec-deepl-status').text('API key is valid.').css('color', '#16a34a');
                } else {
                    $('#ec-deepl-status').text(res.data.message || 'Invalid key').css('color', '#dc2626');
                }
            }).fail(function() {
                $btn.text('Test Key').prop('disabled', false);
                $('#ec-deepl-status').text('Request failed.').css('color', '#dc2626');
            });
        },

        generateTranslations: function() {
            if (!this.eventId || !this.schema) { alert('Select an event and save the form first.'); return; }

            var apiKey = $('#ec-deepl-api-key').val().trim();
            if (!apiKey) { alert('Enter a DeepL API key first.'); return; }

            var languages = ['en'];
            $('.ec-lang-checkbox:checked').each(function() {
                var val = $(this).val();
                if (val !== 'en') languages.push(val);
            });

            if (languages.length < 2) { alert('Select at least one language besides English.'); return; }

            // Store languages in schema settings.
            if (!this.schema.settings) this.schema.settings = {};
            this.schema.settings.languages = languages;
            this.schema.settings.deepl_api_key = apiKey;

            var $btn = $('#ec-deepl-translate');
            $btn.text('Translating...').prop('disabled', true);

            var self = this;
            $.post(ecFormBuilder.ajaxUrl, {
                action: 'ec_deepl_translate_form',
                nonce: ecFormBuilder.nonce,
                event_id: this.eventId,
                api_key: apiKey,
                languages: languages
            }, function(res) {
                $btn.text('Generate Translations').prop('disabled', false);
                if (res.success) {
                    var msg = res.data.message || 'Done';
                    if (res.data.warnings && res.data.warnings.length) {
                        msg += ' (warnings: ' + res.data.warnings.join(', ') + ')';
                    }
                    $('#ec-deepl-status').text(msg).css('color', '#16a34a');
                    self.showToast('Translations generated!');
                } else {
                    $('#ec-deepl-status').text(res.data.message || 'Error').css('color', '#dc2626');
                }
            }).fail(function() {
                $btn.text('Generate Translations').prop('disabled', false);
                $('#ec-deepl-status').text('Request failed.').css('color', '#dc2626');
            });
        },

        // ── Import / Export ──
        exportSchema: function() {
            if (!this.schema) { alert('No form to export.'); return; }
            var blob = new Blob([JSON.stringify(this.schema, null, 2)], { type: 'application/json' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = 'form-schema-' + (this.eventId || 'draft') + '.json';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            this.showToast('Form exported!');
        },

        importSchema: function(e) {
            var self = this;
            var file = e.target.files[0];
            if (!file) return;

            var reader = new FileReader();
            reader.onload = function(evt) {
                try {
                    var imported = JSON.parse(evt.target.result);
                    if (!imported || !imported.steps || !Array.isArray(imported.steps)) {
                        alert('Invalid form schema: missing steps array.');
                        return;
                    }
                    // Validate structure.
                    imported.steps.forEach(function(step) {
                        if (!step.fields || !Array.isArray(step.fields)) {
                            step.fields = [];
                        }
                        if (!step.id) step.id = 'step_' + Date.now() + '_' + Math.random().toString(36).substr(2, 4);
                        if (!step.title) step.title = 'Imported Step';
                    });
                    if (!imported.settings) {
                        imported.settings = {
                            submit_label: 'Submit Registration',
                            success_message: 'Registration complete!',
                            enable_review_step: true,
                            enable_progress_bar: true
                        };
                    }
                    self.schema = imported;
                    self.currentStepIndex = 0;
                    self.selectedFieldId = null;
                    self.renderAll();
                    self.showToast('Form imported successfully!');
                } catch (err) {
                    alert('Error parsing JSON file: ' + err.message);
                }
            };
            reader.readAsText(file);
            // Reset input so same file can be imported again.
            e.target.value = '';
        },

        // ── Utilities ──
        slugify: function(text) {
            return (text || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
        },

        showToast: function(message, isError) {
            var $toast = $('<div class="ec-toast">' + message + '</div>');
            if (isError) $toast.css('background', '#dc2626');
            $('body').append($toast);
            setTimeout(function() { $toast.remove(); }, 3000);
        }
    };

    $(document).ready(function() { FB.init(); });

})(jQuery);
