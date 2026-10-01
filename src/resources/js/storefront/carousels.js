/** Native scrolling keeps every product reachable without JavaScript. */
export default function initCarousels() {
  document.querySelectorAll("[data-storefront-carousel]").forEach((root) => {
    const track = root.querySelector("[data-carousel-track]");
    const slides = [...root.querySelectorAll("[data-carousel-slide]")];
    if (!track || slides.length < 2) return;
    const centered = root.dataset.carouselAlign === "center";
    const motion = window.matchMedia("(prefers-reduced-motion: reduce)");
    const pause = root.querySelector("[data-carousel-pause]");
    const dots = [...root.querySelectorAll("[data-carousel-dot]")];
    let index = 0;
    let timer = null;
    let userPaused = false;
    let hovered = false;
    let focused = false;
    let visible = false;
    const stop = () => {
      window.clearTimeout(timer);
      timer = null;
    };
    const start = () => {
      stop();
      if (
        motion.matches ||
        userPaused ||
        hovered ||
        focused ||
        !visible ||
        document.hidden
      )
        return;
      timer = window.setTimeout(() => {
        go(index + 1);
      }, 6000);
    };
    const go = (next) => {
      if (
        next > index &&
        track.scrollLeft >= track.scrollWidth - track.clientWidth - 2
      )
        next = 0;
      index = (next + slides.length) % slides.length;
      const centerOffset = centered
        ? (track.clientWidth - slides[index].getBoundingClientRect().width) / 2
        : 0;
      const left =
        slides[index].getBoundingClientRect().left -
        track.getBoundingClientRect().left +
        track.scrollLeft -
        centerOffset;
      track.scrollTo({ left, behavior: motion.matches ? "instant" : "smooth" });
      start();
    };
    const updatePause = () => {
      if (!pause) return;
      pause.setAttribute("aria-pressed", String(userPaused));
      pause.setAttribute(
        "aria-label",
        userPaused ? "Putar pergantian otomatis" : "Jeda pergantian otomatis"
      );
      pause.textContent = userPaused ? "▶" : "Ⅱ";
    };
    root.querySelector("[data-carousel-controls]")?.removeAttribute("hidden");
    root
      .querySelector("[data-carousel-prev]")
      ?.addEventListener("click", () => go(index - 1));
    root
      .querySelector("[data-carousel-next]")
      ?.addEventListener("click", () => go(index + 1));
    pause?.addEventListener("click", () => {
      userPaused = !userPaused;
      updatePause();
      start();
    });
    dots.forEach((dot, next) => dot.addEventListener("click", () => go(next)));
    track.addEventListener(
      "scroll",
      () => {
        const left =
          track.getBoundingClientRect().left +
          (centered
            ? (track.clientWidth - slides[0].getBoundingClientRect().width) / 2
            : 0);
        index = slides.reduce(
          (closest, slide, next) =>
            Math.abs(slide.getBoundingClientRect().left - left) <
            Math.abs(slides[closest].getBoundingClientRect().left - left)
              ? next
              : closest,
          0
        );
        dots.forEach((dot, next) =>
          dot.setAttribute("aria-current", String(next === index))
        );
      },
      { passive: true }
    );
    root.addEventListener("mouseenter", () => {
      hovered = true;
      stop();
    });
    root.addEventListener("mouseleave", () => {
      hovered = false;
      start();
    });
    root.addEventListener("focusin", () => {
      focused = true;
      stop();
    });
    root.addEventListener("focusout", (event) => {
      focused = root.contains(event.relatedTarget);
      start();
    });
    track.addEventListener(
      "pointerdown",
      (event) => {
        if (event.pointerType === "touch") {
          userPaused = true;
          updatePause();
          stop();
        }
      },
      { passive: true }
    );
    root.addEventListener("keydown", (event) => {
      if (!["ArrowLeft", "ArrowRight"].includes(event.key)) return;
      event.preventDefault();
      go(index + (event.key === "ArrowRight" ? 1 : -1));
    });
    const observer = new IntersectionObserver(
      ([entry]) => {
        visible = entry.isIntersecting;
        start();
      },
      { threshold: 0.25 }
    );
    observer.observe(root);
    document.addEventListener("visibilitychange", start);
    motion.addEventListener("change", start);
    window.addEventListener(
      "pagehide",
      () => {
        stop();
        observer.disconnect();
      },
      { once: true }
    );
  });
}

export function initImagePreview() {
  const dialog = document.getElementById("product-image-preview");
  if (!dialog || typeof dialog.showModal !== "function") return;
  document.querySelectorAll("[data-image-preview-open]").forEach((button) => {
    button.addEventListener("click", () => dialog.showModal());
  });
  dialog.addEventListener("click", (event) => {
    if (event.target !== dialog) return;
    const bounds = dialog.getBoundingClientRect();
    if (
      event.clientX < bounds.left ||
      event.clientX > bounds.right ||
      event.clientY < bounds.top ||
      event.clientY > bounds.bottom
    )
      dialog.close();
  });
}
