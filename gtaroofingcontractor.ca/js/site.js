(function () {
  var toggle = document.querySelector(".menu-toggle");
  var nav = document.querySelector(".nav");
  var links = document.querySelectorAll(".nav-links a");
  var sections = [];

  links.forEach(function (link) {
    var href = link.getAttribute("href") || "";
    if (href.charAt(0) !== "#") {
      return;
    }
    var section = document.getElementById(href.slice(1));
    if (section) {
      sections.push({ id: href.slice(1), el: section, link: link });
    }
  });

  function closeMenu() {
    if (!nav) {
      return;
    }
    nav.classList.remove("is-open");
    if (toggle) {
      toggle.setAttribute("aria-expanded", "false");
    }
  }

  if (toggle && nav) {
    toggle.addEventListener("click", function () {
      var open = nav.classList.toggle("is-open");
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
    });
  }

  links.forEach(function (link) {
    link.addEventListener("click", closeMenu);
  });

  function setActive() {
    if (!sections.length) {
      return;
    }
    var fromTop = window.scrollY + 120;
    var current = sections[0];
    sections.forEach(function (item) {
      if (item.el.offsetTop <= fromTop) {
        current = item;
      }
    });
    links.forEach(function (link) {
      link.classList.toggle("is-active", current && link === current.link);
    });
  }

  window.addEventListener("scroll", setActive, { passive: true });
  setActive();

  var slides = document.querySelectorAll("[data-hero-slides] .hero-slide");
  var dots = document.querySelectorAll("[data-hero-dots] button");
  var slideIndex = 0;
  var slideTimer;

  function showSlide(index) {
    if (!slides.length) {
      return;
    }
    slideIndex = (index + slides.length) % slides.length;
    slides.forEach(function (slide, i) {
      slide.classList.toggle("is-active", i === slideIndex);
    });
    dots.forEach(function (dot, i) {
      dot.classList.toggle("is-active", i === slideIndex);
    });
  }

  function startSlides() {
    if (slideTimer) {
      window.clearInterval(slideTimer);
    }
    slideTimer = window.setInterval(function () {
      showSlide(slideIndex + 1);
    }, 5000);
  }

  dots.forEach(function (dot, i) {
    dot.addEventListener("click", function () {
      showSlide(i);
      startSlides();
    });
  });
  startSlides();

  var gtaHolder = document.querySelector("[data-gta-map]");
  var gtaCenter = { lat: 43.72, lng: -79.38 };
  var gtaRadius = 58000;

  function showGtaEmbed(holder) {
    holder.innerHTML =
      '<iframe title="Greater Toronto Area on Google Maps" src="https://maps.google.com/maps?q=43.72,-79.38&z=9&hl=en&output=embed" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>' +
      '<div class="gta-circle" aria-hidden="true"></div>';
  }

  function drawGtaCircle() {
    if (!gtaHolder || !window.google || !google.maps) {
      return;
    }
    var map = new google.maps.Map(gtaHolder, {
      center: gtaCenter,
      zoom: 9,
      mapTypeControl: false,
      streetViewControl: false,
      fullscreenControl: true
    });
    new google.maps.Circle({
      map: map,
      center: gtaCenter,
      radius: gtaRadius,
      strokeColor: "#2f7ecb",
      strokeOpacity: 0.95,
      strokeWeight: 3,
      fillColor: "#2f7ecb",
      fillOpacity: 0.22
    });
    new google.maps.Marker({
      map: map,
      position: gtaCenter,
      title: "Greater Toronto Area"
    });
  }

  if (gtaHolder) {
    var mapsKey = (gtaHolder.getAttribute("data-maps-key") || "").trim();
    if (mapsKey) {
      window.initRiosGtaMap = drawGtaCircle;
      var mapsScript = document.createElement("script");
      mapsScript.src = "https://maps.googleapis.com/maps/api/js?key=" + encodeURIComponent(mapsKey) + "&callback=initRiosGtaMap";
      mapsScript.async = true;
      mapsScript.defer = true;
      mapsScript.onerror = function () {
        showGtaEmbed(gtaHolder);
      };
      document.head.appendChild(mapsScript);
    } else {
      showGtaEmbed(gtaHolder);
    }
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
