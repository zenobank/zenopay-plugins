const { registerPaymentMethod } = wc.wcBlocksRegistry;
const { getPaymentMethodData } = wc.wcSettings;
const { createElement } = wp.element;

const settings = getPaymentMethodData("zcpg_gateway") || {};

registerPaymentMethod({
  name: "zcpg_gateway",
  label: settings.title || "Crypto Gateway",
  content: createElement("p", null, settings.description || "Pay with Crypto"),
  edit: createElement("p", null, settings.description || "Pay"),
  canMakePayment: () => true,
  ariaLabel: settings.title || "Crypto Gateway",
  supports: {
    features: settings.supports || [],
  },
});
