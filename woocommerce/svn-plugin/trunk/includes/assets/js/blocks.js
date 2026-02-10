const { registerPaymentMethod } = wc.wcBlocksRegistry;
const { getPaymentMethodData } = wc.wcSettings;
const { createElement } = wp.element;

const settings = getPaymentMethodData("zcpg_gateway") || {};
const iconUrl = settings.icon || "";

registerPaymentMethod({
  name: "zcpg_gateway",
  label: createElement(
    "span",
    { className: "zcpg-blocks-label" },
    iconUrl
      ? createElement("img", {
          src: iconUrl,
          alt: settings.title || "Crypto Gateway",
          style: { maxHeight: "24px", marginRight: "8px", verticalAlign: "middle" },
        })
      : null,
    createElement(
      "span",
      { className: "zcpg-blocks-label-text" },
      settings.title || "Crypto Gateway"
    )
  ),
  content: createElement("p", null, settings.description || "Pay with Crypto"),
  edit: createElement("p", null, settings.description || "Pay"),
  canMakePayment: () => {
    // Respect test mode: only allow admins when enabled.
    if (settings.testMode && !settings.isAdmin) {
      return false;
    }
    return true;
  },
  ariaLabel: settings.title || "Crypto Gateway",
  supports: {
    features: settings.supports || [],
  },
});
