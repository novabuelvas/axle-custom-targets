const sgMail = require('@sendgrid/mail');

exports.handler = async (event, context) => {
  // Enable CORS
  const headers = {
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Headers': 'Content-Type',
    'Content-Type': 'application/json'
  };

  if (event.httpMethod === 'OPTIONS') {
    return { statusCode: 200, headers };
  }

  if (event.httpMethod !== 'POST') {
    return { 
      statusCode: 405, 
      headers,
      body: JSON.stringify({ error: 'Method Not Allowed' })
    };
  }

  try {
    const apiKey = process.env.SENDGRID_API_KEY;
    if (!apiKey) {
      console.error('SENDGRID_API_KEY not configured');
      return {
        statusCode: 500,
        headers,
        body: JSON.stringify({ success: false, error: 'Email service not configured' })
      };
    }

    sgMail.setApiKey(apiKey);

    const formData = JSON.parse(event.body);
    const orderId = formData.orderId || `0716-${Math.random().toString(36).substr(2, 4).toUpperCase()}`;

    // Parse uploaded files
    let attachments = [];
    if (formData.files && formData.files.length > 0) {
      for (const file of formData.files) {
        const base64Content = file.data.includes('base64,') 
          ? file.data.split('base64,')[1] 
          : file.data;
        
        attachments.push({
          filename: file.name,
          content: base64Content,
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
      headers,
      body: JSON.stringify({ success: true, orderId: orderId })
    };
  } catch (error) {
    console.error('Form submission error:', error);
    return {
      statusCode: 500,
      headers,
      body: JSON.stringify({ success: false, error: error.message || 'Form submission failed' })
    };
  }
};
