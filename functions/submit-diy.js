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
    let mockupAttachment = null;
    
    // Add preview PNG
    if (formData.previewPng) {
      const previewContent = formData.previewPng.includes('base64,')
        ? formData.previewPng.split('base64,')[1]
        : formData.previewPng;
      
      mockupAttachment = {
        filename: `mockup-${orderId}.png`,
        content: previewContent,
        type: 'image/png',
        disposition: 'attachment'
      };
      
      attachments.push(mockupAttachment);
    }

    // Add logos
    if (formData.logos && formData.logos.length > 0) {
      for (const logo of formData.logos) {
        const logoContent = logo.data.includes('base64,')
          ? logo.data.split('base64,')[1]
          : logo.data;
        
        attachments.push({
          filename: logo.name,
          content: logoContent,
          type: logo.type,
          disposition: 'attachment'
        });
      }
    }

    // Add design JSON if provided
    if (formData.designJson) {
      attachments.push({
        filename: `design-${orderId}.json`,
        content: Buffer.from(JSON.stringify(formData.designJson, null, 2)).toString('base64'),
        type: 'application/json',
        disposition: 'attachment'
      });
    }

    const orderSummary = `
DIY Design Submission
Order ID: ${orderId}
Name: ${formData.name}
Email: ${formData.email}
Phone: ${formData.phone}
Quantity: ${formData.quantity}
Submitted: ${new Date().toISOString()}
    `;

    // Send confirmation to customer with mockup only
    await sgMail.send({
      to: formData.email,
      from: 'info@axletargets.com',
      subject: `Your DIY Design Proof — ${orderId}`,
      html: `
        <h2>Your DIY Design Proof</h2>
        <p><strong>Order ID:</strong> ${orderId}</p>
        <p><strong>Quantity:</strong> ${formData.quantity}</p>
        <p>Thanks for submitting your design! We've attached your mockup and will review your submission shortly.</p>
        <p>Our team will reach out if we need any adjustments or clarifications.</p>
        <p>Thank you for choosing AXLE Targets!</p>
      `,
      attachments: mockupAttachment ? [mockupAttachment] : []
    });

    // Send full details to info@
    await sgMail.send({
      to: 'info@axletargets.com',
      from: 'info@axletargets.com',
      subject: `New DIY Design: ${orderId}`,
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
