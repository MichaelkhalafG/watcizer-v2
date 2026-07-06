export default function ListingLoading() {
  return (
    <div className="wz-listing-skeleton" style={{
      display: 'grid',
      gridTemplateColumns: '250px 1fr',
      gap: '24px',
      padding: '24px',
      maxWidth: '1400px',
      margin: '0 auto',
    }}>
      <div style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
        {[1,2,3].map(i => (
          <div key={i} style={{
            height: '120px',
            background: 'rgba(0,0,0,0.04)',
            borderRadius: '8px',
            animation: 'pulse 1.5s ease infinite',
          }} />
        ))}
      </div>
      <div style={{
        display: 'grid',
        gridTemplateColumns: 'repeat(4, 1fr)',
        gap: '16px',
      }}>
        {Array.from({length: 12}).map((_, i) => (
          <div key={i} style={{
            aspectRatio: '1',
            background: 'rgba(0,0,0,0.04)',
            borderRadius: '8px',
            animation: 'pulse 1.5s ease infinite',
          }} />
        ))}
      </div>
    </div>
  )
}
