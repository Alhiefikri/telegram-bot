const idr = new Intl.NumberFormat("id-ID");

export default (cost, suggestion) => ({
  delta: "",
  update() {
    const price = this.$refs.price.value;
    if (cost === null || !/^[0-9]{1,17}$/.test(price)) {
      this.delta = "";
      return;
    }
    const delta = BigInt(price) - BigInt(cost);
    const sign = delta < 0n ? "−" : delta > 0n ? "+" : "";
    this.delta = `Selisih pada supplier acuan: ${sign}Rp${idr.format(delta < 0n ? -delta : delta)}`;
  },
  apply() {
    this.$refs.price.value = suggestion;
    this.update();
    this.$refs.price.focus();
  },
});
