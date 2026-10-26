import React from 'react';
import * as LucideIcons from 'lucide-react';

interface DynamicIconProps extends React.SVGProps<SVGSVGElement> {
  name: string;
  size?: number | string;
  className?: string;
  fallback?: React.ElementType;
}

export default function DynamicIcon({
  name,
  size = 16,
  className = '',
  fallback: FallbackIcon = LucideIcons.Layers,
  ...rest
}: DynamicIconProps) {
  // Normalize icon name to PascalCase: e.g. 'users' -> 'Users', 'file-text' -> 'FileText'
  const normalized = name
    ? name
        .replace(/(^\w|-\w)/g, (clear) => clear.replace('-', '').toUpperCase())
    : '';

  // @ts-expect-error Dynamic Lucide index
  const IconComponent = LucideIcons[normalized] || LucideIcons[name] || FallbackIcon;

  return <IconComponent size={size} className={className} {...rest} />;
}
