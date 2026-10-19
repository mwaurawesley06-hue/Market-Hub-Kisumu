(function () {
    'use strict';

    /**
     * Reusable product-image picker.
     * createImageUploader({ input, preview, removeButton, commodityIdGetter, onChanged })
     * The <input type="file" accept="image/*"> stays hidden; the preview area is
     * the click/drop target. Upload is POST commodities/image (multipart).
     */
    function createImageUploader(options) {
        const input = options.input;
        const preview = options.preview;
        const removeButton = options.removeButton || null;
        let busy = false;

        function commodityId() {
            return options.commodityIdGetter ? options.commodityIdGetter() : null;
        }

        function currentUrl() {
            return options.currentUrlGetter ? options.currentUrlGetter() : null;
        }

        function render(url) {
            if (url) {
                preview.innerHTML = `<img src="${window.mpidsUi.escape(url)}" alt="Commodity image preview">`;
                preview.classList.add('has-image');
                if (removeButton) removeButton.hidden = false;
            } else {
                preview.innerHTML = `<i class="fa-solid fa-image"></i><span>Click or drop a photo<br><small>JPG, PNG or WebP · up to 3 MB</small></span>`;
                preview.classList.remove('has-image');
                if (removeButton) removeButton.hidden = true;
            }
        }

        async function upload(file) {
            if (busy) return;
            if (!file) return;
            if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
                window.mpidsUi.toast('Only JPG, PNG, or WebP images are supported.', 'error');
                return;
            }
            if (file.size > 3 * 1024 * 1024) {
                window.mpidsUi.toast('The image is larger than the 3 MB limit.', 'error');
                return;
            }
            const id = commodityId();
            if (!id) {
                window.mpidsUi.toast('Save the commodity first, then add its image.', 'warning');
                return;
            }

            const body = new FormData();
            body.append('commodity_id', String(id));
            body.append('image', file);

            busy = true;
            preview.classList.add('is-uploading');
            try {
                const data = await window.api.upload('commodities/image', body);
                render(data.image_url);
                window.mpidsUi.toast('Image uploaded.', 'success');
                if (options.onChanged) options.onChanged(data);
            } catch (error) {
                window.mpidsUi.toast(error.data?.detail || 'Unable to upload the image.', 'error');
            } finally {
                busy = false;
                preview.classList.remove('is-uploading');
                input.value = '';
            }
        }

        async function remove() {
            const id = commodityId();
            if (!id || busy) return;
            busy = true;
            try {
                await window.api.del(`commodities/${id}/image`);
                render(null);
                window.mpidsUi.toast('Image removed.', 'success');
                if (options.onChanged) options.onChanged(null);
            } catch (error) {
                window.mpidsUi.toast(error.data?.detail || 'Unable to remove the image.', 'error');
            } finally {
                busy = false;
            }
        }

        input.addEventListener('change', () => upload(input.files[0]));
        preview.addEventListener('click', () => { if (!busy) input.click(); });
        preview.addEventListener('dragover', event => { event.preventDefault(); preview.classList.add('is-dragover'); });
        preview.addEventListener('dragleave', () => preview.classList.remove('is-dragover'));
        preview.addEventListener('drop', event => {
            event.preventDefault();
            preview.classList.remove('is-dragover');
            upload(event.dataTransfer.files[0]);
        });
        if (removeButton) removeButton.addEventListener('click', remove);

        render(currentUrl());

        return { render };
    }

    window.mpidsImageUploader = { createImageUploader };
})();
