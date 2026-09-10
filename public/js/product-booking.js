function processAndUpload(input, field, opts = {}) {
    const file = input.files[0];
    if (!file) return;

    const overlay = document.getElementById(`loading-${field}`);
    const statusText = document.getElementById(`status-${field}`);
    const progressBar = document.getElementById(`progress-${field}`);

    // 1. Hiển thị trạng thái đang xử lý
    overlay.classList.remove('hidden');
    statusText.innerText = "Đang tối ưu...";
    progressBar.style.width = "10%";

    const reader = new FileReader();
    reader.readAsDataURL(file);
    reader.onload = function(e) {
        const img = new Image();
        img.src = e.target.result;
        img.onload = function() {
            // 2. Nén ảnh bằng Canvas
            const canvas = document.createElement('canvas');
            let width = img.width;
            let height = img.height;
            const max_size = opts.maxSize || 1200;
            const quality = opts.quality || 0.7;

            if (width > height) {
                if (width > max_size) {
                    height *= max_size / width;
                    width = max_size;
                }
            } else {
                if (height > max_size) {
                    width *= max_size / height;
                    height = max_size;
                }
            }

            canvas.width = width;
            canvas.height = height;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0, width, height);

            canvas.toBlob(function(blob) {
                const safeFileName = file.name.replace(/\.[^/.]+$/, '') + '.jpg';
                const compressedFile = new File([blob], safeFileName, {
                    type: 'image/jpeg'
                });

                statusText.innerText = "Đang tải lên...";

                // 3. Hiển thị preview ngay lập tức bằng ObjectURL (không cần chờ server)
                const previewUrl = URL.createObjectURL(blob);
                const previewImg = document.getElementById(`preview-${field}`);
                const placeholder = document.getElementById(`placeholder-${field}`);
                const checkmark = document.getElementById(`checkmark-${field}`);
                if (previewImg) {
                    previewImg.src = previewUrl;
                    previewImg.classList.remove('hidden');
                }
                if (placeholder) {
                    placeholder.classList.add('hidden');
                }
                if (checkmark) {
                    checkmark.classList.remove('hidden');
                }

                statusText.innerText = "Đang tải lên...";

                // 4. Sử dụng API của Livewire để upload thủ công — window.pdComponentId được set
                // sẵn bởi 1 <script> nhỏ trong product-detail.blade.php (giá trị $_instance->getId()
                // của component, tương đương @this trong Blade) vì file này là JS tĩnh, không được
                // Blade compile.
                window.Livewire.find(window.pdComponentId).upload(field, compressedFile,
                    (uploadedName) => {
                        overlay.classList.add('hidden'); // Hoàn tất
                    },
                    () => {
                        overlay.classList.add('hidden');
                        // Ẩn preview nếu upload thất bại
                        if (previewImg) {
                            previewImg.classList.add('hidden');
                            previewImg.src = '';
                        }
                        if (placeholder) {
                            placeholder.classList.remove('hidden');
                        }
                        if (checkmark) {
                            checkmark.classList.add('hidden');
                        }
                        alert('Lỗi tải lên, vui lòng thử lại.');
                    },
                    (event) => {
                        // Cập nhật % thanh tiến trình thực tế
                        progressBar.style.width = event.detail.progress + "%";
                    }
                );
            }, 'image/jpeg', quality);
        };
    };
}

function pdShareRoom() {
    const shareUrl = window.location.href;

    const notify = (message, type) => {
        window.dispatchEvent(new CustomEvent('notify', {
            detail: { message, type }
        }));
    };

    const fallbackCopy = () => {
        try {
            const input = document.createElement('textarea');
            input.value = shareUrl;
            input.setAttribute('readonly', '');
            input.style.position = 'fixed';
            input.style.opacity = '0';
            document.body.appendChild(input);
            input.select();
            input.setSelectionRange(0, shareUrl.length);
            const ok = document.execCommand('copy');
            document.body.removeChild(input);
            return ok;
        } catch (e) {
            return false;
        }
    };

    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(shareUrl)
            .then(() => notify('Đã sao chép liên kết', 'success'))
            .catch(() => {
                const ok = fallbackCopy();
                notify(ok ? 'Đã sao chép liên kết' : 'Không thể sao chép liên kết', ok ? 'success' : 'error');
            });
        return;
    }

    const ok = fallbackCopy();
    notify(ok ? 'Đã sao chép liên kết' : 'Không thể sao chép liên kết', ok ? 'success' : 'error');
}

(function() {
    const btns = [document.getElementById('pd-wishlist-btn'), document.getElementById('pd-wishlist-btn-mobile')]
        .filter(Boolean);
    if (!btns.length) return;

    const productId = btns[0].getAttribute('data-product-id');

    const notify = (message, type) => {
        window.dispatchEvent(new CustomEvent('notify', {
            detail: { message, type }
        }));
    };

    const paintIcon = (active) => {
        btns.forEach((btn) => {
            const icon = btn.querySelector('svg');
            icon.setAttribute('fill', active ? '#ef4444' : 'none');
            icon.style.color = active ? '#ef4444' : '';
        });
    };

    const setActive = (active) => {
        btns.forEach((btn) => btn.setAttribute('data-active', active ? '1' : '0'));
        paintIcon(active);
    };

    // Xác định trạng thái yêu thích ban đầu (nếu đã đăng nhập)
    const token = localStorage.getItem('auth_token');
    if (token) {
        fetch('/api/wishlist', {
                headers: {
                    'Authorization': 'Bearer ' + token,
                    'Accept': 'application/json'
                },
            })
            .then((res) => res.json())
            .then((json) => {
                const saved = (json.data || []).some((room) => String(room.id) === String(productId));
                setActive(saved);
            })
            .catch(() => {});
    }

    btns.forEach((btn) => {
        btn.addEventListener('click', () => {
            const currentToken = localStorage.getItem('auth_token');
            if (!currentToken) {
                window.dispatchEvent(new CustomEvent('open-auth-modal'));
                return;
            }

            const next = btn.getAttribute('data-active') !== '1';
            setActive(next);

            fetch('/api/wishlist/' + productId + '/toggle', {
                method: 'POST',
                headers: {
                    'Authorization': 'Bearer ' + currentToken,
                    'Accept': 'application/json'
                },
            })
                .then((res) => {
                    if (!res.ok) throw new Error('toggle failed');
                    notify(next ? 'Đã lưu vào yêu thích' : 'Đã bỏ khỏi yêu thích', 'success');
                })
                .catch(() => {
                    setActive(!next);
                    notify('Không thể cập nhật yêu thích. Vui lòng thử lại.', 'error');
                });
        });
    });
})();
