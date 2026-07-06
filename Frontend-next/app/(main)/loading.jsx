export default function MainLoading() {
  return (
    <div className="wz-page-skeleton" style={{
      display: 'flex',
      flexDirection: 'column',
      gap: '24px',
      padding: '24px',
      maxWidth: '1400px',
      margin: '0 auto',
    }}>
      <div style={{
        height: '320px',
        background: 'rgba(0,0,0,0.04)',
        borderRadius: '12px',
        animation: 'pulse 1.5s ease infinite',
      }} />
      <div style={{
        display: 'grid',
        gridTemplateColumns: 'repeat(4, 1fr)',
        gap: '16px',
      }}>
        {Array.from({length: 8}).map((_, i) => (
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
