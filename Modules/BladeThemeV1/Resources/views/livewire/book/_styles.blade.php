<script>
    document.querySelectorAll(".tab-link").forEach(button => {
                button.addEventListener("click", () => {
                    document.querySelectorAll(".tab-pane").forEach(tab => tab.classList.add("hidden"));
                    document.getElementById(button.dataset.tab).classList.remove("hidden");

                    document.querySelectorAll(".tab-link").forEach(btn => btn.classList.remove("active-tab"));
                    button.classList.add("active-tab");
                });
            });
</script>
