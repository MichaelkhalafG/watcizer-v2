export default function ProductLoading() {
  return (
    <div className="wz-product-skeleton" style={{
      display: 'grid',
      gridTemplateColumns: '1fr 1fr',
      gap: '32px',
      padding: '24px',
      maxWidth: '1400px',
      margin: '0 auto',
    }}>
      <div style={{
        aspectRatio: '1',
        background: 'rgba(0,0,0,0.04)',
        borderRadius: '12px',
        animation: 'pulse 1.5s ease infinite',
      }} />
      <div style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
        {[
          { height: '32px', width: '70%' },
          { height: '24px', width: '40%' },
          { height: '28px', width: '30%' },
          { height: '80px', width: '100%' },
          { height: '48px', width: '100%' },
          { height: '48px', width: '60%' },
        ].map((s, i) => (
          <div key={i} style={{
            height: s.height,
            width: s.width,
            background: 'rgba(0,0,0,0.04)',
            borderRadius: '8px',
            animation: 'pulse 1.5s ease infinite',
          }} />
        ))}
      </div>
    </div>
  )
}
