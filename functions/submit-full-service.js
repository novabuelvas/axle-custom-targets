const sgMail = require('@sendgrid/mail');

exports.handler = async (event, context) => {
  if (event.httpMethod !== 'POST') {
    return { statusCode: 405, body: 'Method Not Allowed' };
  }

  sgMail.setApiKey(process.env.SENDGRID_API_KEY);

  try {
    const formData = JSON.parse(event.body);
    const orderId = formData.orderId || `0716-${Math.random().toString(36).substr(2, 4).toUpperCase()}`;

    // Parse uploaded files
    let attachments = [];
    if (formData.files && formData.files.length > 0) {
      for (const file of formData.files) {
        attachments.push({
          filename: file.name,
          content: file.data.split('base64,')[1],
          type: file.type,
          disposition: 'attachment'
        });
      }
    }

    const orderSummary = `
Order ID: ${orderId}
Name: ${formData.name}
Email: ${formData.email}
Phone: ${formData.phone}
Address: ${formData.streetAddress}, ${formData.city}, ${formData.state} ${formData.zip}
Company: ${formData.company || 'N/A'}
Service Type: ${formData.serviceType}
Quantity: ${formData.quantity}
Instructions: ${formData.instructions || 'N/A'}
Submitted: ${new Date().toISOString()}
    `;

    // Send confirmation to customer
    await sgMail.send({
      to: formData.email,
      from: 'info@axletargets.com',
      subject: `Your AXLE Targets Order Received — ${orderId}`,
      html: `
        <h2>Your AXLE Targets Order Received</h2>
        <p><strong>Order ID:</strong> ${orderId}</p>
        <h3>Order Summary</h3>
        <p>
          <strong>Service Type:</strong> ${formData.serviceType}<br>
          <strong>Quantity:</strong> ${formData.quantity}<br>
          <strong>Company:</strong> ${formData.company || 'N/A'}<br>
        </p>
        <p>We've received your order and our team will review your requirements. We'll reach out if we need any clarification.</p>
        <p>Check your email for updates on your order status.</p>
        <p>Thank you for choosing AXLE Targets!</p>
      `
    });

    // Send full details to info@
    await sgMail.send({
      to: 'info@axletargets.com',
      from: 'info@axletargets.com',
      subject: `New Full Service Order: ${orderId}`,
      html: `<pre>${orderSummary}</pre>`,
      attachments: attachments
    });

    return {
      statusCode: 200,
      body: JSON.stringify({ success: true, orderId: orderId })
    };
  } catch (error) {
    console.error('Form submission error:', error);
    return {
      statusCode: 500,
      body: JSON.stringify({ error: 'Form submission failed' })
    };
  }
};
