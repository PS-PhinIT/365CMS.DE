(function () {
    function parseConfig(id) {
        var element = document.getElementById(id);
        if (!element) {
            return {};
        }

        try {
            return JSON.parse(element.textContent || '{}');
        } catch (error) {
            return {};
        }
    }

    function getModal(modalId) {
        var modalElement = document.getElementById(modalId);
        if (!modalElement || !window.bootstrap || !window.bootstrap.Modal) {
            return null;
        }

        return window.bootstrap.Modal.getOrCreateInstance(modalElement);
    }

    function parseDatasetJson(element, key) {
        if (!element) {
            return null;
        }

        var value = element.getAttribute(key);
        if (!value) {
            return null;
        }

        try {
            return JSON.parse(value);
        } catch (error) {
            return null;
        }
    }

    function setValue(id, value) {
        var element = document.getElementById(id);
        if (element) {
            element.value = value;
        }
    }

    function setChecked(id, checked) {
        var element = document.getElementById(id);
        if (element) {
            element.checked = !!checked;
        }
    }

    function resetCategoryForm() {
        setValue('catId', '0');
        setValue('catName', '');
        setValue('catSlug', '');
        setValue('catDesc', '');
        setValue('catScripts', '');
        setValue('catOrder', '0');
        setChecked('catRequired', false);
        setChecked('catActive', true);

        var title = document.getElementById('catModalTitle');
        if (title) {
            title.textContent = 'Kategorie hinzufügen';
        }
    }

    function fillCategoryForm(category) {
        setValue('catId', category.id ? String(category.id) : '0');
        setValue('catName', category.name ? category.name : '');
        setValue('catSlug', category.slug ? category.slug : '');
        setValue('catDesc', category.description ? category.description : '');
        setValue('catScripts', category.scripts ? category.scripts : '');
        setValue('catOrder', category.sort_order ? String(category.sort_order) : '0');
        setChecked('catRequired', !!parseInt(category.is_required ? category.is_required : 0, 10));
        setChecked('catActive', !!parseInt(category.is_active ? category.is_active : 0, 10));

        var title = document.getElementById('catModalTitle');
        if (title) {
            title.textContent = 'Kategorie bearbeiten';
        }
    }

    function resetServiceForm() {
        setValue('serviceId', '0');
        setValue('serviceName', '');
        setValue('serviceSlug', '');
        setValue('serviceProvider', '');
        setValue('serviceDescription', '');
        setValue('serviceCookies', '');
        setValue('serviceCode', '');
        setChecked('serviceEssential', false);
        setChecked('serviceActive', true);

        var categorySelect = document.getElementById('serviceCategory');
        if (categorySelect && categorySelect.options.length > 0) {
            categorySelect.selectedIndex = 0;
        }

        var title = document.getElementById('serviceModalTitle');
        if (title) {
            title.textContent = 'Service hinzufügen';
        }
    }

    function fillServiceForm(service) {
        setValue('serviceId', service.id ? String(service.id) : '0');
        setValue('serviceName', service.name ? service.name : '');
        setValue('serviceSlug', service.slug ? service.slug : '');
        setValue('serviceProvider', service.provider ? service.provider : '');
        setValue('serviceDescription', service.description ? service.description : '');
        setValue('serviceCookies', service.cookie_names ? service.cookie_names : '');
        setValue('serviceCode', service.code_snippet ? service.code_snippet : '');
        setChecked('serviceEssential', !!parseInt(service.is_essential ? service.is_essential : 0, 10));
        setChecked('serviceActive', !!parseInt(service.is_active ? service.is_active : 0, 10));

        var categorySelect = document.getElementById('serviceCategory');
        if (categorySelect) {
            categorySelect.value = service.category_slug ? service.category_slug : 'necessary';
        }

        var title = document.getElementById('serviceModalTitle');
        if (title) {
            title.textContent = 'Service bearbeiten';
        }
    }

    function bindCategoryButtons(config) {
        var modal = getModal(config.categoryModalId || 'categoryModal');

        document.querySelectorAll('.js-cookie-category-create').forEach(function (button) {
            button.addEventListener('click', function () {
                resetCategoryForm();
                if (modal) {
                    modal.show();
                }
            });
        });

        document.querySelectorAll('.js-cookie-category-edit').forEach(function (button) {
            button.addEventListener('click', function () {
                var category = parseDatasetJson(button, 'data-cookie-category');
                if (!category) {
                    return;
                }

                fillCategoryForm(category);
                if (modal) {
                    modal.show();
                }
            });
        });
    }

    function bindServiceButtons(config) {
        var modal = getModal(config.serviceModalId || 'serviceModal');

        document.querySelectorAll('.js-cookie-service-create').forEach(function (button) {
            button.addEventListener('click', function () {
                resetServiceForm();
                if (modal) {
                    modal.show();
                }
            });
        });

        document.querySelectorAll('.js-cookie-service-edit').forEach(function (button) {
            button.addEventListener('click', function () {
                var service = parseDatasetJson(button, 'data-cookie-service');
                if (!service) {
                    return;
                }

                fillServiceForm(service);
                if (modal) {
                    modal.show();
                }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var config = parseConfig('cookie-manager-config');
        bindCategoryButtons(config);
        bindServiceButtons(config);
    });
})();