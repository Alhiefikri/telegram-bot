export default function initSupportChooser(root = document) {
  root.querySelectorAll("[data-support-chooser]").forEach((chooser) => {
    if (chooser.dataset.supportChooserInitialized === "true") {
      return;
    }

    const select = chooser.querySelector("[data-support-select]");
    const field = chooser.querySelector("[data-support-select-field]");
    const options = chooser.querySelector("[data-support-options]");
    const fallback = chooser.querySelector("[data-support-fallback]");
    const groups = Array.from(chooser.querySelectorAll("[data-support-group]"));
    const firstOption =
      select &&
      Array.from(select.options).find(
        (option) =>
          !option.disabled &&
          groups.some((group) => group.dataset.supportGroup === option.value)
      );

    if (!firstOption || !field || !options || !fallback) {
      return;
    }

    const showSelectedGroup = () => {
      groups.forEach((group) => {
        group.hidden = group.dataset.supportGroup !== select.value;
      });
    };

    select.value = firstOption.value;
    showSelectedGroup();
    select.addEventListener("change", showSelectedGroup);
    groups.forEach((group) => {
      const title = group.querySelector("[data-support-group-title]");
      if (title) {
        title.hidden = true;
      }
    });
    options.open = true;
    fallback.hidden = true;
    field.hidden = false;
    chooser.dataset.supportChooserInitialized = "true";
  });
}
