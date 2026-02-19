// Call 'LoadCheckoutPaymentContext' method and pass a function as parameter to get access to the Checkout context and the PaymentOptions object.

LoadCheckoutPaymentContext(function (Checkout, PaymentOptions) {
  // Create a new instance of external Payment Option and set its properties.
  var AcmeExternalPaymentOption = PaymentOptions.ExternalPayment({
    // Set the option's unique ID as it is configured on the Payment Provider so they can be related at the checkout.
    id: "acme_redirect",

    // Indicate that this payment option implements backend to backend payment processing.
    version: "v2",

    // This parameter renders the billing information form and requires the information to the consumer.
    fields: {
      billing_address: true,
    },

    // This function handles the order submission event.
    onSubmit: function (callback) {
      // Gather any additional information needed.
      var extraCartData = {
        currency: Checkout.getData("order.cart.currency"),
      };

      // Use the Checkout.processPayment method to make a request to your app's API and get the redirect URL through our backend.
      Checkout.processPayment(extraCartData)
        .then(function (responseData) {
          // In case of success, redirect the consumer to the generated URL.
          window.parent.location.href = responseData.redirect_url;
        })
        .catch(function (error) {
          // In case of error, show a proper error message to the consumer.
          Checkout.showErrorCode(error.response.data.message);
        });
    },
  });

  // Finally, add the Payment Option to the Checkout object so it can be render according to the configuration set on the Payment Provider.
  Checkout.addPaymentOption(AcmeExternalPaymentOption);

  // Or remove payment option loading.
  Checkout.unLoadPaymentMethod("acme_redirect");
  //    onSubmit: function(callback) {
  //             if (Checkout.getData('totalPrice') < 3) {
  //                 Checkout.showErrorCode('order_total_price_too_small');
  //             }
  //             ...
  //         });
});
