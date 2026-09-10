document.addEventListener('DOMContentLoaded', function() {
    // Ảnh có caption trong TinyMCE (image_caption: true) được bọc <figure><img><figcaption>.
    // Biên tập viên hay chỉ điền caption mà quên ô Alt text riêng trong dialog ảnh — điền lại
    // alt từ caption cho những ảnh còn thiếu, tránh mất điểm accessibility/SEO ảnh.
    document.querySelectorAll('.post-content figure').forEach(function(figure) {
        const img = figure.querySelector('img');
        const figcaption = figure.querySelector('figcaption');
        if (img && figcaption && !img.getAttribute('alt')) {
            img.setAttribute('alt', figcaption.textContent.trim());
        }
    });

    // Id của heading + cây mục lục giờ được build sẵn ở server (TableOfContents::build, xem
    // PostDetail.php) — JS chỉ còn lo phần tương tác: cuộn mượt, thu/phóng khung, và tô đậm
    // mục đang đọc theo vị trí cuộn (progressive enhancement, không ảnh hưởng gì nếu JS lỗi).
    const headings = document.querySelectorAll('.post-content h1, .post-content h2, .post-content h3');
    headings.forEach(heading => heading.classList.add('text-primary'));

    // Header dùng position:sticky và tự co giãn chiều cao khi cuộn (xem header-hero-sticky),
    // nên top cố định bằng Tailwind (top-20) sẽ lúc đúng lúc không — đo chiều cao header thật
    // và ghim cột "Nội dung" ngay dưới nó, cập nhật lại mỗi khi cuộn/resize.
    const tocSticky = document.getElementById('toc-sticky');
    const stickyHeader = document.querySelector('.header-hero-sticky');

    if (tocSticky && stickyHeader) {
        const syncTocTop = () => {
            tocSticky.style.top = (stickyHeader.offsetHeight + 16) + 'px';
        };

        syncTocTop();
        window.addEventListener('resize', syncTocTop);

        let tocTopTicking = false;
        window.addEventListener('scroll', () => {
            if (tocTopTicking) return;
            tocTopTicking = true;
            requestAnimationFrame(() => {
                syncTocTop();
                tocTopTicking = false;
            });
        });
    }

    // Nội dung giờ có 2 bản trong DOM (cột sticky lg+ và modal mobile, xem trên) — cùng
    // chọn hết qua .toc-link, bản nào đang ẩn theo breakpoint thì không ai bấm được nên
    // không cần phân biệt.
    const tocLinks = document.querySelectorAll('.toc-link');

    tocLinks.forEach(link => {
        link.addEventListener('click', function(event) {
            event.preventDefault();
            const targetId = this.getAttribute('href').slice(1);
            const targetElement = document.getElementById(targetId);
            if (!targetElement) return;

            const offset = 70;
            const offsetPosition = targetElement.getBoundingClientRect().top + window.pageYOffset - offset;

            window.scrollTo({ top: offsetPosition, behavior: 'smooth' });
            history.pushState(null, null, `#${targetId}`);
        });
    });

    if ('IntersectionObserver' in window && tocLinks.length > 0) {
        const tocHeadings = Array.from(tocLinks)
            .map(link => document.getElementById(link.getAttribute('href').slice(1)))
            .filter(Boolean);

        const setActiveLink = (id) => {
            tocLinks.forEach(link => {
                link.classList.toggle('toc-link-active', link.getAttribute('href') === `#${id}`);
            });
        };

        const observer = new IntersectionObserver((entries) => {
            const visible = entries.find(entry => entry.isIntersecting);
            if (visible) {
                setActiveLink(visible.target.id);
            }
        }, { rootMargin: '-80px 0px -70% 0px' });

        tocHeadings.forEach(heading => observer.observe(heading));
    }
});
