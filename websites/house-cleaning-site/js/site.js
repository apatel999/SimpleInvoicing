(function () {
  var toggle = document.querySelector(".menu-toggle");
  var nav = document.querySelector(".nav");
  if (toggle && nav) {
    toggle.addEventListener("click", function () {
      nav.classList.toggle("is-open");
      toggle.setAttribute("aria-expanded", nav.classList.contains("is-open") ? "true" : "false");
    });
  }

  var form = document.querySelector("[data-quote-form]");
  var notice = document.querySelector("[data-form-notice]");
  if (form && notice) {
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      notice.classList.add("is-visible");
      form.reset();
      notice.scrollIntoView({ behavior: "smooth", block: "center" });
    });
  }
})();
