import assert from "node:assert/strict";
import test from "node:test";
import { salesChart } from "../../resources/js/admin-dashboard.js";

const chart = (points, width, tooltipWidth = 0) => {
  const component = salesChart(points);
  component.$refs = {
    plot: { clientWidth: width },
    tooltip: { offsetWidth: tooltipWidth },
  };
  component.$nextTick = (callback) => callback();

  return component;
};

const points = [
  { key: "2026-10-19", range_label: "19 Okt 2026", formatted: "Rp1.000", orders: 1, share: 10 },
  { key: "2026-10-20", range_label: "20 Okt 2026", formatted: "Rp2.000", orders: 2, share: 20 },
  { key: "2026-10-21", range_label: "21 Okt 2026", formatted: "Rp7.000", orders: 7, share: 70 },
];

test("showing a bucket exposes its detail and centers the tooltip", () => {
  const component = chart(points, 600);

  assert.equal(component.activePoint(), null);

  component.show(1);

  assert.equal(component.activePoint().range_label, "20 Okt 2026");
  assert.equal(component.activePoint().formatted, "Rp2.000");
  assert.equal(component.tooltipLeft, "300px");
});

test("tooltip stays inside the plot for the first and last bucket", () => {
  const component = chart(points, 600);

  component.show(0);
  assert.equal(component.tooltipLeft, "104px");

  component.show(2);
  assert.equal(component.tooltipLeft, "496px");
});

test("narrow plots keep the tooltip within the available width", () => {
  const component = chart(points, 120);

  component.show(0);
  assert.equal(component.tooltipLeft, "60px");

  component.show(2);
  assert.equal(component.tooltipLeft, "60px");
});

test("blur and unknown buckets clear the active detail", () => {
  const component = chart(points, 600);

  component.show(2);
  component.hide();
  assert.equal(component.activePoint(), null);

  component.show(undefined);
  assert.equal(component.activeIndex, null);
  assert.equal(component.activePoint(), null);
});

test("the measured tooltip width drives the clamp on narrow plots", () => {
  const phone = chart(points, 216, 176);

  phone.show(0);
  assert.equal(phone.tooltipLeft, "88px");

  phone.show(2);
  assert.equal(phone.tooltipLeft, "128px");

  const desktop = chart(points, 600, 208);
  desktop.show(0);
  assert.equal(desktop.tooltipLeft, "104px");
});

test("missing plot or empty buckets keep the tooltip at the origin", () => {
  const withoutPlot = salesChart(points);
  withoutPlot.show(1);
  assert.equal(withoutPlot.tooltipLeft, "0px");

  const empty = chart([], 600);
  empty.show(0);
  assert.equal(empty.tooltipLeft, "0px");
  assert.equal(empty.activePoint(), null);
});
